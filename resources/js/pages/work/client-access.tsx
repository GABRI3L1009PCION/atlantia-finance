import { Head, router, useForm } from '@inertiajs/react';
import type { Users } from 'lucide-react';
import {
    KeyRound,
    MailPlus,
    ShieldCheck,
    UserCheck,
    UserX,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import {
    ConfirmDialog,
    EmptyRow,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    input,
    panel,
    primaryButton,
    relativeTime,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type Contact = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    company_id: number;
    company: string;
    contact_role: string;
    role_label: string;
    status: 'active' | 'revoked';
    two_factor: boolean;
    password_pending: boolean;
    last_access: string | null;
    granted_at: string;
};

type PendingInvitation = {
    id: string;
    name: string;
    email: string;
    company: string;
    role_label: string;
    expires_at: string;
    sent_count: number;
};

type Credentials = { name: string; email: string; password: string };

function CredentialsDialog({
    credentials,
    onClose,
}: {
    credentials: Credentials | null;
    onClose: () => void;
}) {
    const [copied, setCopied] = useState(false);

    return (
        <Dialog
            open={credentials !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setCopied(false);
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogTitle>Acceso creado</DialogTitle>
                <DialogDescription>
                    Entrega estos datos a {credentials?.name}. La contraseña
                    solo se muestra ahora y vence en 7 días si no la usa. Al
                    ingresar deberá crear su propia contraseña y activar Google
                    Authenticator.
                </DialogDescription>
                <dl className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <div>
                        <dt className="text-xs font-bold text-slate-500 uppercase">
                            Usuario
                        </dt>
                        <dd className="font-mono font-bold break-all">
                            {credentials?.email}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs font-bold text-slate-500 uppercase">
                            Contraseña temporal
                        </dt>
                        <dd className="font-mono text-xl font-black tracking-wider">
                            {credentials?.password}
                        </dd>
                    </div>
                </dl>
                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        className={secondaryButton}
                        onClick={() => {
                            void navigator.clipboard
                                ?.writeText(
                                    `Usuario: ${credentials?.email}\nContraseña temporal: ${credentials?.password}`,
                                )
                                .then(() => setCopied(true));
                        }}
                    >
                        {copied ? 'Copiado' : 'Copiar datos'}
                    </button>
                    <button className={primaryButton} onClick={onClose}>
                        Listo
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

type Props = {
    credentials?: Credentials | null;
    companies: { id: number; name: string }[];
    contacts: Contact[];
    invitations: PendingInvitation[];
    offices: { id: number; name: string; client_require_two_factor: boolean }[];
    roles: Record<string, string>;
    summary: {
        active: number;
        revoked: number;
        pending: number;
        without_two_factor: number;
    };
};

const roleHints: Record<string, string> = {
    owner_contact: 'Ve todo el portal de su empresa, incluida la nómina.',
    admin_contact: 'Todo el portal, excepto nómina.',
    accounting_contact:
        'Resumen financiero, documentos, reportes y mensajes. Sin nómina.',
    payroll_contact: 'Nómina, documentos y mensajes.',
    fiscal_contact: 'Obligaciones, FEL, documentos y mensajes.',
    viewer: 'Solo consulta: no sube ni responde nada.',
};

function InviteClientDialog({
    open,
    onClose,
    companies,
    roles,
}: {
    open: boolean;
    onClose: () => void;
    companies: Props['companies'];
    roles: Props['roles'];
}) {
    const [byEmail, setByEmail] = useState(false);
    const form = useForm({
        name: '',
        email: '',
        company_id: companies[0]?.id ?? 0,
        contact_role: 'accounting_contact',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(
            byEmail
                ? '/office/client-access/invitations'
                : '/office/client-access',
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    onClose();
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>Crear acceso para un cliente</DialogTitle>
                <DialogDescription>
                    {byEmail
                        ? 'Le enviamos un correo para que cree su contraseña.'
                        : 'Creas su usuario con una contraseña temporal que tú le entregas. Al entrar, el sistema le pedirá crear la suya y activar la verificación en dos pasos.'}{' '}
                    Solo verá la empresa que elijas y no ocupa un cupo de tu
                    plan.
                </DialogDescription>
                <label className="inline-flex items-center gap-2 text-sm font-semibold">
                    <input
                        type="checkbox"
                        className="size-4 accent-[#640000]"
                        checked={byEmail}
                        onChange={(event) => setByEmail(event.target.checked)}
                    />
                    Prefiero enviarle una invitación por correo
                </label>
                <form onSubmit={submit} className="grid gap-3">
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase">
                        Nombre completo
                        <input
                            className={`${input} mt-1`}
                            required
                            maxLength={120}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        {form.errors.name && (
                            <span className="text-[11px] font-normal text-red-700 normal-case">
                                {form.errors.name}
                            </span>
                        )}
                    </label>
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase">
                        Correo electrónico
                        <input
                            type="email"
                            className={`${input} mt-1`}
                            required
                            value={form.data.email}
                            onChange={(event) =>
                                form.setData('email', event.target.value)
                            }
                        />
                        {form.errors.email && (
                            <span className="text-[11px] font-normal text-red-700 normal-case">
                                {form.errors.email}
                            </span>
                        )}
                    </label>
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase">
                        Empresa
                        <select
                            className={`${input} mt-1`}
                            value={form.data.company_id}
                            onChange={(event) =>
                                form.setData(
                                    'company_id',
                                    Number(event.target.value),
                                )
                            }
                        >
                            {companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-bold tracking-wide text-slate-600 uppercase">
                        Qué puede hacer
                        <select
                            className={`${input} mt-1`}
                            value={form.data.contact_role}
                            onChange={(event) =>
                                form.setData('contact_role', event.target.value)
                            }
                        >
                            {Object.entries(roles).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <span className="mt-1 block text-[11px] font-normal text-slate-500 normal-case">
                            {roleHints[form.data.contact_role]}
                        </span>
                    </label>
                    <div className="flex justify-end gap-2 border-t border-slate-100 pt-3">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-bold"
                        >
                            Cancelar
                        </button>
                        <button
                            className={primaryButton}
                            disabled={form.processing || companies.length === 0}
                        >
                            <MailPlus className="size-4" />{' '}
                            {byEmail ? 'Enviar invitación' : 'Crear acceso'}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ActivityDialog({
    contact,
    onClose,
}: {
    contact: Contact | null;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<
        | { action: string; label: string; at: string; ip: string | null }[]
        | null
    >(null);
    const contactId = contact?.id ?? null;

    useEffect(() => {
        if (contactId === null) {
            return;
        }

        let cancelled = false;

        fetch(`/office/client-access/${contactId}/activity`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((data) => !cancelled && setRows(data.activity))
            .catch(() => !cancelled && setRows([]));

        return () => {
            cancelled = true;
            setRows(null);
        };
    }, [contactId]);

    return (
        <Dialog
            open={contact !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogTitle>Actividad de {contact?.name}</DialogTitle>
                <DialogDescription>
                    {contact?.company} · último acceso{' '}
                    {contact?.last_access
                        ? relativeTime(contact.last_access)
                        : 'nunca'}
                </DialogDescription>
                <div className="max-h-80 overflow-y-auto">
                    {rows === null ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            Cargando…
                        </p>
                    ) : rows.length === 0 ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            Aún no hay actividad registrada de este contacto.
                        </p>
                    ) : (
                        <ul className="divide-y divide-slate-100 text-sm">
                            {rows.map((row, index) => (
                                <li
                                    key={`${row.at}-${index}`}
                                    className="flex items-center justify-between gap-3 py-2"
                                >
                                    <span className="font-semibold">
                                        {row.label}
                                    </span>
                                    <span className="text-xs text-slate-500">
                                        {formatDate(row.at, true)}
                                        {row.ip ? ` · ${row.ip}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default function ClientAccess(props: Props) {
    const { companies, contacts, invitations, offices, roles, summary } = props;
    const [inviting, setInviting] = useState(false);
    const [confirming, setConfirming] = useState<{
        kind: 'password' | 'revoke';
        contact: Contact;
    } | null>(null);
    const [dismissed, setDismissed] = useState<Credentials | null>(null);
    const credentials =
        props.credentials && props.credentials !== dismissed
            ? props.credentials
            : null;
    const [viewing, setViewing] = useState<Contact | null>(null);
    const [filter, setFilter] = useState('');
    const visible = contacts.filter((contact) =>
        `${contact.name} ${contact.email} ${contact.company}`
            .toLowerCase()
            .includes(filter.toLowerCase()),
    );

    return (
        <>
            <Head title="Accesos de clientes" />
            <Shell>
                <PageHeader
                    title="Accesos de clientes"
                    subtitle="Quién de tus empresas clientes entra al portal, qué ve y cuándo lo usó por última vez"
                    actions={
                        <button
                            className={primaryButton}
                            onClick={() => setInviting(true)}
                        >
                            <MailPlus className="size-4" /> Crear acceso de
                            cliente
                        </button>
                    }
                />
                <Notice message={null} />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        ['Contactos activos', summary.active, UserCheck],
                        ['Invitaciones pendientes', summary.pending, MailPlus],
                        ['Accesos revocados', summary.revoked, KeyRound],
                        [
                            'Activos sin 2 pasos',
                            summary.without_two_factor,
                            ShieldCheck,
                        ],
                    ].map(([label, value, Icon]) => {
                        const IconComponent = Icon as typeof Users;

                        return (
                            <div key={String(label)} className={`${panel} p-3`}>
                                <p className="flex items-center justify-between text-xs font-bold tracking-wide text-[#526b92] uppercase">
                                    {String(label)}
                                    <IconComponent className="size-5 text-[#640000]" />
                                </p>
                                <p className="mt-2 text-2xl font-black text-[#061633]">
                                    {Number(value)}
                                </p>
                            </div>
                        );
                    })}
                </section>

                <section className={`${panel} p-4`}>
                    <SectionHeader title="Seguridad de los contactos" />
                    <p className="mb-3 text-sm text-slate-600">
                        Exige la verificación en dos pasos a los contactos de
                        clientes de la oficina: al entrar, deberán activarla
                        antes de usar el portal.
                    </p>
                    <ul className="divide-y divide-slate-100">
                        {offices.map((office) => (
                            <li
                                key={office.id}
                                className="flex flex-wrap items-center justify-between gap-2 py-2"
                            >
                                <span className="font-bold">{office.name}</span>
                                <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-[#640000]"
                                        checked={
                                            office.client_require_two_factor
                                        }
                                        onChange={(event) =>
                                            router.patch(
                                                `/office/client-access/offices/${office.id}/policy`,
                                                {
                                                    required:
                                                        event.target.checked,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                    Exigir verificación en dos pasos
                                </label>
                            </li>
                        ))}
                    </ul>
                </section>

                {invitations.length > 0 && (
                    <section className={`${panel} overflow-x-auto p-4`}>
                        <SectionHeader title="Invitaciones pendientes" />
                        <table className="w-full min-w-[720px] text-sm">
                            <thead>
                                <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                    <th className="px-3 py-2">Contacto</th>
                                    <th className="px-3 py-2">Empresa</th>
                                    <th className="px-3 py-2">Rol</th>
                                    <th className="px-3 py-2">Vence</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {invitations.map((invitation) => (
                                    <tr key={invitation.id}>
                                        <td className="px-3 py-2">
                                            <p className="font-bold">
                                                {invitation.name}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {invitation.email}
                                            </p>
                                        </td>
                                        <td className="px-3 py-2">
                                            {invitation.company}
                                        </td>
                                        <td className="px-3 py-2">
                                            {invitation.role_label}
                                        </td>
                                        <td className="px-3 py-2">
                                            {formatDate(invitation.expires_at)}
                                        </td>
                                        <td className="space-x-2 px-3 py-2 text-right whitespace-nowrap">
                                            <button
                                                className={secondaryButton}
                                                onClick={() =>
                                                    router.post(
                                                        `/office/client-access/invitations/${invitation.id}/resend`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Reenviar
                                            </button>
                                            <button
                                                className={secondaryButton}
                                                onClick={() =>
                                                    router.delete(
                                                        `/office/client-access/invitations/${invitation.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Cancelar
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}

                <section className={`${panel} overflow-x-auto p-4`}>
                    <SectionHeader
                        title={`Contactos con acceso (${contacts.length})`}
                        action={
                            <input
                                className={`${input} max-w-64`}
                                placeholder="Buscar contacto o empresa"
                                value={filter}
                                onChange={(event) =>
                                    setFilter(event.target.value)
                                }
                            />
                        }
                    />
                    <table className="w-full min-w-[900px] text-sm">
                        <thead>
                            <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                <th className="px-3 py-2">Contacto</th>
                                <th className="px-3 py-2">Empresa</th>
                                <th className="px-3 py-2">Rol</th>
                                <th className="px-3 py-2">2 pasos</th>
                                <th className="px-3 py-2">Último acceso</th>
                                <th className="px-3 py-2">Estado</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {visible.map((contact) => (
                                <tr key={contact.id}>
                                    <td className="px-3 py-2">
                                        <p className="font-bold">
                                            {contact.name}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            {contact.email}
                                        </p>
                                    </td>
                                    <td className="px-3 py-2">
                                        {contact.company}
                                    </td>
                                    <td className="px-3 py-2">
                                        <select
                                            className="h-9 rounded-lg border border-slate-300 bg-white px-2 text-xs font-semibold"
                                            value={contact.contact_role}
                                            disabled={
                                                contact.status !== 'active'
                                            }
                                            onChange={(event) =>
                                                router.patch(
                                                    `/office/client-access/${contact.id}/role`,
                                                    {
                                                        contact_role:
                                                            event.target.value,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {Object.entries(roles).map(
                                                ([key, label]) => (
                                                    <option
                                                        key={key}
                                                        value={key}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </td>
                                    <td className="px-3 py-2">
                                        <span
                                            className={badge(
                                                contact.two_factor
                                                    ? 'green'
                                                    : 'amber',
                                            )}
                                        >
                                            {contact.two_factor
                                                ? 'Activa'
                                                : 'Sin activar'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {contact.last_access
                                            ? relativeTime(contact.last_access)
                                            : 'Nunca'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span
                                            className={badge(
                                                contact.status === 'active'
                                                    ? 'green'
                                                    : 'red',
                                            )}
                                        >
                                            {contact.status !== 'active'
                                                ? 'Revocado'
                                                : contact.password_pending
                                                  ? 'Aún no ingresa'
                                                  : 'Activo'}
                                        </span>
                                    </td>
                                    <td className="space-x-2 px-3 py-2 text-right whitespace-nowrap">
                                        <button
                                            className={secondaryButton}
                                            onClick={() => setViewing(contact)}
                                        >
                                            Actividad
                                        </button>
                                        {contact.status === 'active' && (
                                            <button
                                                className={secondaryButton}
                                                onClick={() =>
                                                    setConfirming({
                                                        kind: 'password',
                                                        contact,
                                                    })
                                                }
                                            >
                                                Nueva contraseña
                                            </button>
                                        )}
                                        {contact.status === 'active' ? (
                                            <button
                                                className={secondaryButton}
                                                onClick={() =>
                                                    setConfirming({
                                                        kind: 'revoke',
                                                        contact,
                                                    })
                                                }
                                            >
                                                Revocar
                                            </button>
                                        ) : (
                                            <button
                                                className={secondaryButton}
                                                onClick={() =>
                                                    router.post(
                                                        `/office/client-access/${contact.id}/restore`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Restablecer
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {visible.length === 0 && (
                                <EmptyRow
                                    colSpan={7}
                                    text="Aún no hay contactos con acceso. Usa “Crear acceso de cliente” para crear el primero."
                                />
                            )}
                        </tbody>
                    </table>
                </section>
            </Shell>

            <ConfirmDialog
                open={confirming !== null}
                tone={confirming?.kind === 'revoke' ? 'danger' : 'primary'}
                icon={
                    confirming?.kind === 'revoke' ? (
                        <UserX className="size-7" />
                    ) : (
                        <KeyRound className="size-7" />
                    )
                }
                title={
                    confirming?.kind === 'revoke'
                        ? '¿Revocar este acceso?'
                        : '¿Generar una contraseña temporal nueva?'
                }
                description={
                    confirming?.kind === 'revoke' ? (
                        <>
                            <strong>{confirming.contact.name}</strong> dejará de
                            entrar al portal de{' '}
                            <strong>{confirming.contact.company}</strong>.
                            Puedes restablecer su acceso cuando quieras.
                        </>
                    ) : (
                        <>
                            Se cerrarán las sesiones abiertas de{' '}
                            <strong>{confirming?.contact.name}</strong> y se
                            mostrará una contraseña temporal que debes
                            entregarle. Al ingresar tendrá que crear una nueva.
                        </>
                    )
                }
                confirmLabel={
                    confirming?.kind === 'revoke'
                        ? 'Sí, revocar acceso'
                        : 'Generar contraseña'
                }
                onConfirm={() => {
                    if (confirming) {
                        router.post(
                            `/office/client-access/${confirming.contact.id}/${confirming.kind === 'revoke' ? 'revoke' : 'password'}`,
                            {},
                            { preserveScroll: true },
                        );
                    }

                    setConfirming(null);
                }}
                onClose={() => setConfirming(null)}
            />
            <CredentialsDialog
                credentials={credentials}
                onClose={() => setDismissed(props.credentials ?? null)}
            />
            <InviteClientDialog
                open={inviting}
                onClose={() => setInviting(false)}
                companies={companies}
                roles={roles}
            />
            <ActivityDialog
                contact={viewing}
                onClose={() => setViewing(null)}
            />
        </>
    );
}
