import { Head, Link } from '@inertiajs/react';
import {
    Crown,
    Eye,
    Layers,
    LockKeyhole,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    Users,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    Field,
    Notice,
    Pill,
    SensitiveAction,
    ago,
    ccButton,
    ccInput,
    panel,
} from '@/components/control-center/kit';

type Staff = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    status: string;
    two_factor: boolean;
    require_two_factor: boolean;
    last_login_at: string | null;
    sessions: number;
};
type Role = {
    key: string;
    label: string;
    description: string;
    permissions: string[];
};

const card =
    'rounded-2xl border border-slate-200 bg-white shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]';

const initials = (name: string): string =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('') || '?';

function Modal({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <div className="fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-sm">
            <div className={`${panel} w-full max-w-xl space-y-4 p-5`}>
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-bold text-slate-900">
                        {title}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="text-slate-500 hover:text-slate-900"
                    >
                        <X className="size-5" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

function RolePill({ user }: { user: Staff }) {
    const owner = user.role === 'saas_owner';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold whitespace-nowrap ring-1 ${owner ? 'bg-[#e0a526]/15 text-[#7a4b00] ring-[#e0a526]/50' : 'bg-[#7a0010]/5 text-[#7a0010] ring-[#7a0010]/20'}`}
        >
            {owner && <Crown className="size-3.5" />}
            {user.role_label}
        </span>
    );
}

export default function SaasUsers({
    users,
    roles,
    can_manage,
    current_user_id,
}: {
    users: Staff[];
    roles: Role[];
    permission_labels: Record<string, string>;
    can_manage: boolean;
    current_user_id: number;
}) {
    const [creating, setCreating] = useState(false);
    const [viewing, setViewing] = useState<Staff | null>(null);
    const [managing, setManaging] = useState<Staff | null>(null);
    const [newUser, setNewUser] = useState({
        name: '',
        email: '',
        role: 'saas_support',
    });
    const [roleChange, setRoleChange] = useState<Record<number, string>>({});
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const term = search.trim().toLowerCase();
    const visible = users.filter(
        (user) =>
            (status === '' || user.status === status) &&
            (term === '' ||
                user.name.toLowerCase().includes(term) ||
                user.email.toLowerCase().includes(term)),
    );
    const roleOf = (key: string): Role | undefined =>
        roles.find((role) => role.key === key);
    const kpis = [
        {
            label: users.length === 1 ? 'Usuario' : 'Usuarios',
            value: users.length,
            icon: Users,
            tile: '#7a0010',
        },
        {
            label: 'SaaS Owner',
            value: users.filter((user) => user.role === 'saas_owner').length,
            icon: Crown,
            tile: '#b8862b',
        },
        {
            label: 'Administradores',
            value: users.filter((user) => user.role === 'saas_admin').length,
            icon: Layers,
            tile: '#7a0010',
        },
        {
            label: 'Verificación en dos pasos activa',
            value: users.filter((user) => user.two_factor).length,
            icon: ShieldCheck,
            tile: '#b8862b',
        },
    ];
    const iconButton =
        'grid size-8 place-items-center rounded-lg border border-[#7a0010]/40 text-[#7a0010] hover:bg-[#7a0010]/5';

    return (
        <>
            <Head title="Usuarios SaaS · Control Center" />
            <div className="space-y-4">
                <header className="border-l-4 border-[#e0a526] pl-5">
                    <h1 className="font-serif text-4xl font-bold text-slate-900">
                        Usuarios
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-slate-600">
                        Gestiona el acceso del personal de Atlantia Systems a la
                        plataforma y asigna sus roles. El último SaaS Owner
                        activo está protegido.
                    </p>
                </header>
                <Notice />

                <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    {kpis.map((kpi) => (
                        <div
                            key={kpi.label}
                            className={`${card} flex items-center gap-4 p-4`}
                        >
                            <span
                                className="grid size-12 shrink-0 place-items-center rounded-xl text-white"
                                style={{ background: kpi.tile }}
                            >
                                <kpi.icon className="size-6" />
                            </span>
                            <span>
                                <span className="block font-serif text-2xl leading-tight font-bold text-slate-900">
                                    {kpi.value}
                                </span>
                                <span className="block text-sm text-slate-700">
                                    {kpi.label}
                                </span>
                            </span>
                        </div>
                    ))}
                </section>

                {can_manage && (
                    <button
                        type="button"
                        onClick={() => setCreating(true)}
                        className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#7a0010] px-5 text-sm font-bold text-white shadow-[0_10px_24px_-14px_rgba(122,0,16,.9)] transition hover:bg-[#8f0b20]"
                    >
                        <Plus className="size-5" /> Crear usuario
                    </button>
                )}

                <section className={`${card} p-3`}>
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <h2 className="flex-1 border-l-4 border-[#e0a526] pl-3 font-serif text-xl font-bold text-slate-900">
                            Lista de usuarios
                        </h2>
                        <label className="relative w-full max-w-sm">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
                            <input
                                className={`${ccInput} pl-9`}
                                placeholder="Buscar usuario o correo…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </label>
                        <select
                            className={`${ccInput} max-w-[200px]`}
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                        >
                            <option value="">Todos los estados</option>
                            <option value="active">Activos</option>
                            <option value="inactive">Inactivos</option>
                        </select>
                    </div>
                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="min-w-full text-[13px] text-slate-800">
                            <thead>
                                <tr className="bg-[#7a0010] text-left font-semibold whitespace-nowrap text-white">
                                    <th className="px-4 py-3">Usuario</th>
                                    <th className="px-3 py-3">Rol</th>
                                    <th className="px-3 py-3">Estado</th>
                                    <th className="px-3 py-3">
                                        Verificación en dos pasos
                                    </th>
                                    <th className="px-3 py-3">Último acceso</th>
                                    <th className="px-3 py-3 text-center">
                                        Sesiones
                                    </th>
                                    <th className="px-3 py-3 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {visible.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-10 text-center text-slate-500"
                                        >
                                            {users.length === 0
                                                ? 'Sin personal SaaS.'
                                                : 'Ningún usuario coincide con la búsqueda.'}
                                        </td>
                                    </tr>
                                )}
                                {visible.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="hover:bg-slate-50"
                                    >
                                        <td className="min-w-[240px] px-4 py-3">
                                            <span className="flex items-center gap-3">
                                                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-[#e0a526]/25 text-sm font-black text-[#7a4b00]">
                                                    {initials(user.name)}
                                                </span>
                                                <span>
                                                    <span className="block font-bold text-slate-900">
                                                        {user.name}
                                                    </span>
                                                    <span className="block text-xs text-slate-500">
                                                        {user.email}
                                                    </span>
                                                </span>
                                            </span>
                                        </td>
                                        <td className="px-3 py-3">
                                            <RolePill user={user} />
                                        </td>
                                        <td className="px-3 py-3">
                                            <Pill
                                                tone={
                                                    user.status === 'active'
                                                        ? 'green'
                                                        : 'slate'
                                                }
                                            >
                                                {user.status === 'active'
                                                    ? 'Activo'
                                                    : 'Inactivo'}
                                            </Pill>
                                        </td>
                                        <td className="px-3 py-3">
                                            {user.two_factor ? (
                                                <Pill tone="green">Activo</Pill>
                                            ) : (
                                                <Pill tone="red">Sin 2FA</Pill>
                                            )}
                                            {user.require_two_factor && (
                                                <span className="block text-[10px] text-slate-500">
                                                    Obligatorio
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            {ago(user.last_login_at)}
                                        </td>
                                        <td className="px-3 py-3 text-center">
                                            {user.sessions}
                                        </td>
                                        <td className="px-3 py-3">
                                            <div className="flex items-center justify-center gap-1.5">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setViewing(user)
                                                    }
                                                    className={iconButton}
                                                    aria-label={`Ver a ${user.name}`}
                                                    title="Ver"
                                                >
                                                    <Eye className="size-4" />
                                                </button>
                                                {can_manage &&
                                                    user.user_id !==
                                                        current_user_id && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setManaging(
                                                                    user,
                                                                )
                                                            }
                                                            className={
                                                                iconButton
                                                            }
                                                            aria-label={`Gestionar a ${user.name}`}
                                                            title="Rol, estado, sesiones y 2FA"
                                                        >
                                                            <Pencil className="size-4" />
                                                        </button>
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Link
                        href="/control-center/roles"
                        className="mt-3 inline-flex items-center gap-1.5 px-1 text-xs font-semibold text-[#7a0010] hover:underline"
                    >
                        <LockKeyhole className="size-3.5" /> Ver qué puede hacer
                        cada rol
                    </Link>
                </section>
            </div>

            {creating && (
                <Modal
                    title="Crear usuario SaaS"
                    onClose={() => setCreating(false)}
                >
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label="Nombre">
                            <input
                                className={ccInput}
                                value={newUser.name}
                                onChange={(e) =>
                                    setNewUser({
                                        ...newUser,
                                        name: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Correo">
                            <input
                                type="email"
                                className={ccInput}
                                value={newUser.email}
                                onChange={(e) =>
                                    setNewUser({
                                        ...newUser,
                                        email: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Rol SaaS" wide>
                            <select
                                className={ccInput}
                                value={newUser.role}
                                onChange={(e) =>
                                    setNewUser({
                                        ...newUser,
                                        role: e.target.value,
                                    })
                                }
                            >
                                {roles.map((role) => (
                                    <option key={role.key} value={role.key}>
                                        {role.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    <p className="text-xs text-slate-500">
                        {roleOf(newUser.role)?.description} Recibirá un enlace
                        para definir su contraseña y deberá activar 2FA antes de
                        entrar.
                    </p>
                    <div className="flex justify-end">
                        <SensitiveAction
                            url="/control-center/saas-users"
                            data={newUser}
                            label="Crear usuario"
                            title="Crear usuario SaaS"
                            description="Confirma con tu contraseña y tu código 2FA."
                            buttonClassName={ccButton}
                        />
                    </div>
                </Modal>
            )}

            {viewing && (
                <Modal title={viewing.name} onClose={() => setViewing(null)}>
                    <div className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-full bg-[#e0a526]/25 text-base font-black text-[#7a4b00]">
                            {initials(viewing.name)}
                        </span>
                        <div>
                            <p className="text-sm text-slate-600">
                                {viewing.email}
                            </p>
                            <RolePill user={viewing} />
                        </div>
                    </div>
                    <p className="text-sm text-slate-600">
                        {roleOf(viewing.role)?.description}
                    </p>
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        {[
                            [
                                'Estado',
                                viewing.status === 'active'
                                    ? 'Activo'
                                    : 'Inactivo',
                            ],
                            [
                                'Verificación en dos pasos',
                                viewing.two_factor
                                    ? 'Activa'
                                    : 'Sin configurar',
                            ],
                            [
                                '2FA obligatorio',
                                viewing.require_two_factor ? 'Sí' : 'No',
                            ],
                            ['Último acceso', ago(viewing.last_login_at)],
                            ['Sesiones abiertas', String(viewing.sessions)],
                            [
                                'Permisos del rol',
                                String(
                                    roleOf(viewing.role)?.permissions.length ??
                                        0,
                                ),
                            ],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="rounded-lg bg-slate-50 px-3 py-2"
                            >
                                <dt className="text-xs text-slate-500">
                                    {label}
                                </dt>
                                <dd className="font-semibold text-slate-900">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <Link
                        href="/control-center/roles"
                        className="inline-flex items-center gap-1.5 text-sm font-semibold text-[#7a0010] hover:underline"
                    >
                        <LockKeyhole className="size-4" /> Ver los permisos de
                        cada rol
                    </Link>
                </Modal>
            )}

            {managing && (
                <Modal
                    title={`Gestionar a ${managing.name}`}
                    onClose={() => setManaging(null)}
                >
                    <Field label="Rol SaaS">
                        <div className="flex flex-wrap items-center gap-2">
                            <select
                                className={`${ccInput} max-w-xs`}
                                value={roleChange[managing.id] ?? managing.role}
                                onChange={(e) =>
                                    setRoleChange({
                                        ...roleChange,
                                        [managing.id]: e.target.value,
                                    })
                                }
                            >
                                {roles.map((role) => (
                                    <option key={role.key} value={role.key}>
                                        {role.label}
                                    </option>
                                ))}
                            </select>
                            {(roleChange[managing.id] ?? managing.role) !==
                                managing.role && (
                                <SensitiveAction
                                    url={`/control-center/saas-users/${managing.id}/role`}
                                    data={{ role: roleChange[managing.id] }}
                                    label="Cambiar rol"
                                    title={`Cambiar rol de ${managing.name}`}
                                    description="Sus sesiones se cerrarán para aplicar los nuevos permisos."
                                />
                            )}
                        </div>
                    </Field>
                    <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                        <SensitiveAction
                            danger={managing.status === 'active'}
                            url={`/control-center/saas-users/${managing.id}/${managing.status === 'active' ? 'deactivate' : 'activate'}`}
                            label={
                                managing.status === 'active'
                                    ? 'Desactivar'
                                    : 'Activar'
                            }
                            title={`${managing.status === 'active' ? 'Desactivar' : 'Activar'} a ${managing.name}`}
                        />
                        <SensitiveAction
                            url={`/control-center/saas-users/${managing.id}/revoke-sessions`}
                            label="Cerrar sesiones"
                            title={`Cerrar todas las sesiones de ${managing.name}`}
                        />
                        <SensitiveAction
                            url={`/control-center/saas-users/${managing.id}/${managing.require_two_factor ? 'relax-2fa' : 'require-2fa'}`}
                            label={
                                managing.require_two_factor
                                    ? 'No exigir 2FA'
                                    : 'Exigir 2FA'
                            }
                            title="Política de 2FA del usuario"
                            description="La política global puede exigir 2FA a todo el personal de todos modos."
                        />
                    </div>
                    <p className="text-xs text-slate-500">
                        Cada acción pide tu contraseña y tu código 2FA, y queda
                        en la auditoría.
                    </p>
                </Modal>
            )}
        </>
    );
}
