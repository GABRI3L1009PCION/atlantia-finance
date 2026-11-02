import { Head } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    CreditCard,
    Crown,
    Gauge,
    Layers,
    LifeBuoy,
    LockKeyhole,
    Server,
    Settings,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { Notice } from '@/components/control-center/kit';

type Role = {
    key: string;
    label: string;
    description: string;
    permissions: string[];
    members: number;
};

const roleIcons: Record<string, LucideIcon> = {
    saas_owner: Crown,
    saas_admin: Settings,
    saas_operations: Layers,
    saas_billing: CreditCard,
    saas_support: LifeBuoy,
    saas_security: ShieldCheck,
    saas_analyst: BarChart3,
};

/** Areas of the Control Center; a permission belongs to the area of its second segment (saas.<area>.<action>). */
const areas: { label: string; icon: LucideIcon; segments: string[] }[] = [
    {
        label: 'Centro de control',
        icon: Gauge,
        segments: ['dashboard', 'settings'],
    },
    {
        label: 'Organizaciones',
        icon: Building2,
        segments: ['organizations', 'offices', 'users'],
    },
    {
        label: 'Facturación',
        icon: CreditCard,
        segments: ['plans', 'subscriptions', 'billing', 'payments'],
    },
    {
        label: 'Producto',
        icon: Layers,
        segments: ['features', 'usage', 'flags'],
    },
    {
        label: 'Servicios',
        icon: Server,
        segments: ['services', 'jobs', 'integrations', 'luzma'],
    },
    {
        label: 'Seguridad',
        icon: ShieldCheck,
        segments: ['security', 'support_access', 'audit', 'logs'],
    },
    {
        label: 'Soporte',
        icon: LifeBuoy,
        segments: ['tickets', 'incidents'],
    },
];

const areaOf = (permission: string): string => permission.split('.')[1] ?? '';

function SectionTitle({
    title,
    subtitle,
}: {
    title: string;
    subtitle: string;
}) {
    return (
        <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-l-4 border-[#e0a526] pl-4">
            <h2 className="font-serif text-2xl font-bold text-slate-900">
                {title}
            </h2>
            <p className="text-sm text-slate-600">{subtitle}</p>
        </div>
    );
}

export default function Roles({
    roles,
    permission_labels: labels,
}: {
    roles: Role[];
    permission_labels: Record<string, string>;
}) {
    const [open, setOpen] = useState<string | null>(null);
    const holders = (permission: string): string =>
        roles
            .filter((role) => role.permissions.includes(permission))
            .map((role) => role.label)
            .join(', ');
    const card =
        'rounded-2xl border border-slate-200 bg-white shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]';

    return (
        <>
            <Head title="Roles y permisos · Control Center" />
            <div className="space-y-6">
                <header className="flex items-start gap-4">
                    <div className="border-l-4 border-[#e0a526] pl-5">
                        <h1 className="font-serif text-4xl font-bold text-slate-900">
                            Roles y permisos
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-600">
                            Qué puede hacer cada rol del personal de Atlantia
                            Systems en el Control Center. Los roles se asignan a
                            cada persona desde Usuarios SaaS; no son roles de
                            las organizaciones cliente.
                        </p>
                    </div>
                </header>
                <Notice />

                <section className="space-y-3">
                    <SectionTitle
                        title="Roles"
                        subtitle="Define los roles de acceso a la plataforma y su alcance general."
                    />
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        {roles.map((role) => {
                            const Icon = roleIcons[role.key] ?? LockKeyhole;
                            const expanded = open === role.key;

                            return (
                                <article
                                    key={role.key}
                                    className={`${card} flex flex-col p-4`}
                                >
                                    <div className="flex gap-3">
                                        <span
                                            className={`grid size-12 shrink-0 place-items-center rounded-xl text-white ${role.key === 'saas_owner' ? 'bg-[#b8862b]' : 'bg-[#7a0010]'}`}
                                        >
                                            <Icon className="size-6" />
                                        </span>
                                        <div className="min-w-0">
                                            <h3 className="font-bold text-slate-900">
                                                {role.label}
                                            </h3>
                                            <p className="text-xs leading-snug text-slate-600">
                                                {role.description}
                                            </p>
                                        </div>
                                    </div>
                                    <p className="mt-3 text-xs text-slate-500">
                                        {role.members} persona
                                        {role.members === 1 ? '' : 's'} ·{' '}
                                        {role.permissions.length} permisos
                                    </p>
                                    {expanded && (
                                        <ul className="mt-2 max-h-56 space-y-1 overflow-y-auto border-t border-slate-100 pt-2 text-xs text-slate-700">
                                            {role.permissions.map(
                                                (permission) => (
                                                    <li
                                                        key={permission}
                                                        className="flex gap-2"
                                                    >
                                                        <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-[#e0a526]" />
                                                        {labels[permission] ??
                                                            permission}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                    <button
                                        type="button"
                                        aria-expanded={expanded}
                                        onClick={() =>
                                            setOpen(expanded ? null : role.key)
                                        }
                                        className="mt-auto self-center pt-3"
                                    >
                                        <span className="inline-flex h-8 items-center rounded-lg border-2 border-[#7a0010]/70 px-8 text-xs font-bold text-[#7a0010] transition hover:bg-[#7a0010]/5">
                                            {expanded ? 'Ocultar' : 'Ver rol'}
                                        </span>
                                    </button>
                                </article>
                            );
                        })}
                    </div>
                </section>

                <section className="space-y-3">
                    <SectionTitle
                        title="Permisos"
                        subtitle="Conjuntos de accesos específicos que se asignan a los roles. Pasa el cursor sobre un permiso para ver qué roles lo tienen."
                    />
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        {areas.map((area) => {
                            const permissions = Object.keys(labels).filter(
                                (permission) =>
                                    area.segments.includes(areaOf(permission)),
                            );

                            return (
                                <article
                                    key={area.label}
                                    className={`${card} p-4`}
                                >
                                    <h3 className="flex items-center gap-2.5 font-bold text-slate-900">
                                        <area.icon className="size-6 text-[#7a0010]" />
                                        {area.label}
                                    </h3>
                                    <ul className="mt-3 space-y-1.5 text-[13px] text-slate-700">
                                        {permissions.map((permission) => (
                                            <li
                                                key={permission}
                                                className="flex gap-2"
                                                title={`Roles: ${holders(permission) || 'ninguno'}`}
                                            >
                                                <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-[#e0a526]" />
                                                {labels[permission]}
                                            </li>
                                        ))}
                                    </ul>
                                </article>
                            );
                        })}
                    </div>
                </section>
            </div>
        </>
    );
}
