import { Head, router } from '@inertiajs/react';
import {
    Ban,
    CheckCircle2,
    Clock3,
    MailPlus,
    RefreshCcw,
    Send,
    TimerOff,
    UserPlus,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    EmptyRow,
    InviteDialog,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
    panel,
    primaryButton,
    relativeTime,
} from '@/components/office-admin/kit';
import type { FormOptions, OfficeScope } from '@/components/office-admin/kit';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
    useTableControls,
} from '@/components/table-controls';

type Invitation = {
    public_id: string;
    email: string;
    name: string | null;
    role_name: string;
    office_name: string;
    invited_by: string | null;
    sent_at: string;
    sent_count: number;
    expires_at: string;
    accepted_at: string | null;
    canceled_at: string | null;
    status: 'pending' | 'expired' | 'accepted' | 'canceled';
};

type Filter = 'all' | Invitation['status'];

const statusLabel: Record<
    Invitation['status'],
    [string, 'amber' | 'red' | 'green' | 'slate']
> = {
    pending: ['Pendiente de aceptar', 'amber'],
    expired: ['Vencida', 'red'],
    accepted: ['Confirmada', 'green'],
    canceled: ['Cancelada', 'slate'],
};

const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-slate-300 bg-white text-[#640000] transition hover:border-[#c9a36a] hover:bg-amber-50';

const searchText = (invitation: Invitation): string =>
    [
        invitation.name,
        invitation.email,
        invitation.role_name,
        invitation.office_name,
        invitation.invited_by,
    ].join(' ');

export default function Invitations({
    officeScope,
    invitations,
    formOptions,
    permissions,
    notice,
}: {
    officeScope: OfficeScope;
    invitations: Invitation[];
    formOptions: FormOptions;
    permissions: Record<string, boolean>;
    notice: string | null;
}) {
    const [inviteOpen, setInviteOpen] = useState(false);
    const [filter, setFilter] = useState<Filter>('all');
    const [office, setOffice] = useState('');

    const count = (status: Invitation['status']): number =>
        invitations.filter((invitation) => invitation.status === status).length;
    const cards: {
        key: Filter;
        label: string;
        hint: string;
        value: number;
        icon: LucideIcon;
        tone: string;
    }[] = [
        {
            key: 'all',
            label: 'Enviadas',
            hint: 'Todas las invitaciones',
            value: invitations.length,
            icon: Send,
            tone: 'bg-blue-50 text-blue-700',
        },
        {
            key: 'pending',
            label: 'Pendientes de aceptar',
            hint: 'Esperan respuesta',
            value: count('pending'),
            icon: Clock3,
            tone: 'bg-amber-50 text-amber-700',
        },
        {
            key: 'accepted',
            label: 'Confirmadas',
            hint: 'Ya forman parte del equipo',
            value: count('accepted'),
            icon: CheckCircle2,
            tone: 'bg-emerald-50 text-emerald-700',
        },
        {
            key: 'expired',
            label: 'Vencidas',
            hint: 'Reenvía para renovarlas',
            value: count('expired'),
            icon: TimerOff,
            tone: 'bg-red-50 text-red-700',
        },
        {
            key: 'canceled',
            label: 'Canceladas',
            hint: 'Anuladas por la oficina',
            value: count('canceled'),
            icon: Ban,
            tone: 'bg-slate-100 text-slate-700',
        },
    ];

    const offices = useMemo(
        () =>
            Array.from(
                new Set(
                    invitations.map((invitation) => invitation.office_name),
                ),
            ).sort(),
        [invitations],
    );
    const predicate = useMemo(
        () => (invitation: Invitation) =>
            (filter === 'all' || invitation.status === filter) &&
            (office === '' || invitation.office_name === office),
        [filter, office],
    );
    const table = useTableControls(invitations, { searchText, predicate });
    const activeCard = cards.find((card) => card.key === filter);

    return (
        <>
            <Head title="Invitaciones" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Invitaciones"
                    subtitle="Invita colaboradores a tu oficina y sigue el estado de cada invitación"
                    actions={
                        permissions.canInvite && (
                            <button
                                type="button"
                                className={primaryButton}
                                onClick={() => setInviteOpen(true)}
                            >
                                <UserPlus className="size-4" /> Invitar usuario
                            </button>
                        )
                    }
                />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {cards.map(
                        ({ key, label, hint, value, icon: Icon, tone }) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    setFilter(filter === key ? 'all' : key)
                                }
                                className={`rounded-xl p-3 text-left transition hover:brightness-95 ${tone} ${filter === key ? 'ring-2 ring-[#640000]' : ''}`}
                                aria-pressed={filter === key}
                            >
                                <Icon className="size-5" />
                                <p className="mt-2 text-2xl font-black">
                                    {value}
                                </p>
                                <p className="text-xs font-semibold">{label}</p>
                                <p className="text-[11px] opacity-80">{hint}</p>
                            </button>
                        ),
                    )}
                </section>

                <section className={`${panel} overflow-hidden`}>
                    <div className="p-3 pb-0">
                        <SectionHeader
                            title={`${activeCard && filter !== 'all' ? activeCard.label : 'Invitaciones'} (${table.count}${table.count !== table.total ? ` de ${table.total}` : ''})`}
                        />
                    </div>
                    <TableToolbar
                        search={table.search}
                        onSearch={table.setSearch}
                        placeholder="Buscar por nombre, correo, rol u oficina"
                        pageSize={table.pageSize}
                        onPageSize={table.setPageSize}
                    >
                        {offices.length > 1 && (
                            <TableFilterSelect
                                label="Todas las oficinas"
                                value={office}
                                onChange={setOffice}
                                options={offices.map((value) => ({
                                    value,
                                    label: value,
                                }))}
                            />
                        )}
                    </TableToolbar>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-3 py-2">Invitado</th>
                                    <th className="px-3 py-2">Rol</th>
                                    <th className="px-3 py-2">Oficina</th>
                                    <th className="px-3 py-2">Invitó</th>
                                    <th className="px-3 py-2">Enviada</th>
                                    <th className="px-3 py-2">
                                        Vence / Confirmó
                                    </th>
                                    <th className="px-3 py-2">Estado</th>
                                    <th className="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {table.pageRows.map((invitation) => {
                                    const [label, tone] =
                                        statusLabel[invitation.status];
                                    const actionable =
                                        ['pending', 'expired'].includes(
                                            invitation.status,
                                        ) && permissions.canInvite;

                                    return (
                                        <tr key={invitation.public_id}>
                                            <td className="px-3 py-2">
                                                <p className="font-bold text-[#061633]">
                                                    {invitation.name ??
                                                        invitation.email}
                                                </p>
                                                <p className="text-[11px] text-[#526b92]">
                                                    {invitation.email}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2">
                                                {invitation.role_name}
                                            </td>
                                            <td className="px-3 py-2">
                                                {invitation.office_name}
                                            </td>
                                            <td className="px-3 py-2">
                                                {invitation.invited_by ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                {formatDate(invitation.sent_at)}
                                                <span className="block text-[11px] text-[#526b92]">
                                                    {relativeTime(
                                                        invitation.sent_at,
                                                    )}
                                                    {invitation.sent_count >
                                                        1 &&
                                                        ` · ${invitation.sent_count} envíos`}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                {invitation.status ===
                                                'accepted'
                                                    ? formatDate(
                                                          invitation.accepted_at,
                                                          true,
                                                      )
                                                    : invitation.status ===
                                                        'canceled'
                                                      ? formatDate(
                                                            invitation.canceled_at,
                                                        )
                                                      : formatDate(
                                                            invitation.expires_at,
                                                            true,
                                                        )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span className={badge(tone)}>
                                                    {label}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                {actionable && (
                                                    <div className="flex items-center gap-1.5">
                                                        <button
                                                            type="button"
                                                            className={
                                                                iconButton
                                                            }
                                                            title="Reenviar invitación"
                                                            aria-label={`Reenviar invitación a ${invitation.email}`}
                                                            onClick={() =>
                                                                router.post(
                                                                    `/office/invitations/${invitation.public_id}/resend`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <RefreshCcw className="size-4" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className={`${iconButton} text-red-700`}
                                                            title="Cancelar invitación"
                                                            aria-label={`Cancelar invitación de ${invitation.email}`}
                                                            onClick={() =>
                                                                confirm(
                                                                    `¿Cancelar la invitación de ${invitation.email}?`,
                                                                ) &&
                                                                router.post(
                                                                    `/office/invitations/${invitation.public_id}/cancel`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <X className="size-4" />
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {table.pageRows.length === 0 && (
                                    <EmptyRow
                                        colSpan={8}
                                        text={
                                            table.total === 0
                                                ? 'Aún no has enviado invitaciones. Usa «Invitar usuario» para sumar colaboradores a tu oficina.'
                                                : 'No hay invitaciones que coincidan con la búsqueda o los filtros.'
                                        }
                                    />
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
                        noun="invitaciones"
                    />
                </section>

                {permissions.canInvite && invitations.length === 0 && (
                    <p className="flex items-center gap-2 text-xs text-[#526b92]">
                        <MailPlus className="size-4" /> Cada invitación reserva
                        un cupo del plan hasta que se acepta, vence o se
                        cancela.
                    </p>
                )}
            </Shell>
            <InviteDialog
                open={inviteOpen}
                onClose={() => setInviteOpen(false)}
                scope={officeScope}
                options={formOptions}
            />
        </>
    );
}
