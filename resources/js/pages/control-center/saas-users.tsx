import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    CCPage,
    Field,
    Panel,
    Pill,
    SensitiveAction,
    Table,
    ago,
    ccButton,
    ccInput,
    statusTone,
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

export default function SaasUsers({
    users,
    roles,
    permission_labels,
    can_manage,
    current_user_id,
}: {
    users: Staff[];
    roles: Role[];
    permission_labels: Record<string, string>;
    can_manage: boolean;
    current_user_id: number;
}) {
    const [newUser, setNewUser] = useState({
        name: '',
        email: '',
        role: 'saas_support',
    });
    const [roleChange, setRoleChange] = useState<Record<number, string>>({});

    return (
        <>
            <Head title="Usuarios SaaS · Control Center" />
            <CCPage
                title="Usuarios SaaS"
                subtitle="Personal administrativo de Atlantia Systems. Los roles SaaS no son roles de las organizaciones cliente. El último SaaS Owner activo está protegido."
            >
                {can_manage && (
                    <Panel title="Agregar usuario SaaS">
                        <div className="grid gap-3 md:grid-cols-4">
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
                            <Field label="Rol SaaS">
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
                                    {roles.map((r) => (
                                        <option key={r.key} value={r.key}>
                                            {r.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <div className="flex items-end">
                                <SensitiveAction
                                    url="/control-center/saas-users"
                                    data={newUser}
                                    label="Crear usuario"
                                    title="Crear usuario SaaS"
                                    description="Recibirá un enlace para definir su contraseña y deberá activar 2FA antes de entrar."
                                    buttonClassName={ccButton}
                                />
                            </div>
                        </div>
                    </Panel>
                )}
                <Panel title={`${users.length} usuario(s) SaaS`}>
                    <Table
                        headers={[
                            'Usuario',
                            'Rol',
                            'Estado',
                            '2FA',
                            'Último acceso',
                            'Sesiones',
                            can_manage ? 'Acciones' : '',
                        ]}
                        empty={users.length === 0 && 'Sin personal SaaS.'}
                    >
                        {users.map((user) => (
                            <tr key={user.id}>
                                <td className="px-2 py-2">
                                    <span className="font-semibold text-white">
                                        {user.name}
                                    </span>
                                    <span className="block text-slate-500">
                                        {user.email}
                                    </span>
                                </td>
                                <td className="px-2 py-2">{user.role_label}</td>
                                <td className="px-2 py-2">
                                    <Pill
                                        tone={
                                            statusTone[
                                                user.status === 'active'
                                                    ? 'active'
                                                    : 'disabled'
                                            ]
                                        }
                                    >
                                        {user.status === 'active'
                                            ? 'Activo'
                                            : 'Inactivo'}
                                    </Pill>
                                </td>
                                <td className="px-2 py-2">
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
                                <td className="px-2 py-2">
                                    {ago(user.last_login_at)}
                                </td>
                                <td className="px-2 py-2">{user.sessions}</td>
                                <td className="px-2 py-2">
                                    {can_manage &&
                                        user.user_id !== current_user_id && (
                                            <div className="flex flex-wrap items-center gap-1">
                                                <select
                                                    className={`${ccInput} h-8 w-36`}
                                                    value={
                                                        roleChange[user.id] ??
                                                        user.role
                                                    }
                                                    onChange={(e) =>
                                                        setRoleChange({
                                                            ...roleChange,
                                                            [user.id]:
                                                                e.target.value,
                                                        })
                                                    }
                                                >
                                                    {roles.map((r) => (
                                                        <option
                                                            key={r.key}
                                                            value={r.key}
                                                        >
                                                            {r.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {(roleChange[user.id] ??
                                                    user.role) !==
                                                    user.role && (
                                                    <SensitiveAction
                                                        url={`/control-center/saas-users/${user.id}/role`}
                                                        data={{
                                                            role: roleChange[
                                                                user.id
                                                            ],
                                                        }}
                                                        label="Cambiar rol"
                                                        title={`Cambiar rol de ${user.name}`}
                                                        description="Sus sesiones se cerrarán para aplicar los nuevos permisos."
                                                    />
                                                )}
                                                <SensitiveAction
                                                    danger={
                                                        user.status === 'active'
                                                    }
                                                    url={`/control-center/saas-users/${user.id}/${user.status === 'active' ? 'deactivate' : 'activate'}`}
                                                    label={
                                                        user.status === 'active'
                                                            ? 'Desactivar'
                                                            : 'Activar'
                                                    }
                                                    title={`${user.status === 'active' ? 'Desactivar' : 'Activar'} a ${user.name}`}
                                                />
                                                <SensitiveAction
                                                    url={`/control-center/saas-users/${user.id}/revoke-sessions`}
                                                    label="Cerrar sesiones"
                                                    title={`Cerrar todas las sesiones de ${user.name}`}
                                                />
                                                <SensitiveAction
                                                    url={`/control-center/saas-users/${user.id}/${user.require_two_factor ? 'relax-2fa' : 'require-2fa'}`}
                                                    label={
                                                        user.require_two_factor
                                                            ? 'No exigir 2FA'
                                                            : 'Exigir 2FA'
                                                    }
                                                    title="Política de 2FA del usuario"
                                                    description="La política global puede exigir 2FA a todo el personal de todos modos."
                                                />
                                            </div>
                                        )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
                <Panel title="Roles SaaS y permisos">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        {roles.map((role) => (
                            <div
                                key={role.key}
                                className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3"
                            >
                                <p className="font-bold text-white">
                                    {role.label}
                                </p>
                                <p className="mt-1 text-[11px] text-slate-400">
                                    {role.description}
                                </p>
                                <ul className="mt-2 space-y-0.5 text-[10px] text-slate-500">
                                    {role.permissions.map((p) => (
                                        <li key={p}>
                                            • {permission_labels[p] ?? p}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </Panel>
            </CCPage>
        </>
    );
}
