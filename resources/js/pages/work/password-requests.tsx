import { Head, router } from '@inertiajs/react';
import { Check, KeyRound, ShieldCheck, ShieldX, X } from 'lucide-react';
import { useState } from 'react';
import {
    ConfirmDialog,
    EmptyRow,
    Notice,
    PageHeader,
    SectionHeader,
    Shell,
    badge,
    formatDate,
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

type RequestRow = {
    id: number;
    kind: 'client' | 'member';
    account_name: string;
    claimed_name: string;
    email: string;
    reason: string;
    status: 'pending' | 'approved' | 'rejected';
    office: string | null;
    ip: string | null;
    note: string | null;
    requested_at: string;
    name_matches: boolean;
};

type Credentials = { name: string; email: string; password: string };

const statusBadge = {
    pending: ['En espera', 'amber'],
    approved: ['Aprobada', 'green'],
    rejected: ['Rechazada', 'red'],
} as const;

export default function PasswordRequests({
    requests,
    credentials,
}: {
    requests: RequestRow[];
    credentials?: Credentials | null;
}) {
    const [dismissed, setDismissed] = useState<Credentials | null>(null);
    const [copied, setCopied] = useState(false);
    const visibleCredentials =
        credentials && credentials !== dismissed ? credentials : null;
    const pending = requests.filter((row) => row.status === 'pending');
    const history = requests.filter((row) => row.status !== 'pending');

    const [approving, setApproving] = useState<RequestRow | null>(null);
    const [rejecting, setRejecting] = useState<RequestRow | null>(null);
    const [sending, setSending] = useState(false);

    function approve(row: RequestRow) {
        setSending(true);
        router.post(
            `/office/password-requests/${row.id}/approve`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSending(false);
                    setApproving(null);
                },
            },
        );
    }

    function reject(row: RequestRow, note: string) {
        setSending(true);
        router.post(
            `/office/password-requests/${row.id}/reject`,
            { note },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSending(false);
                    setRejecting(null);
                },
            },
        );
    }

    return (
        <>
            <Head title="Solicitudes de contraseña" />
            <Shell>
                <PageHeader
                    title="Solicitudes de contraseña"
                    subtitle="Personas de tu oficina y clientes que olvidaron su contraseña y esperan tu autorización"
                />
                <Notice message={null} />

                <section className={`${panel} overflow-x-auto p-4`}>
                    <SectionHeader title={`Por resolver (${pending.length})`} />
                    <table className="w-full min-w-[860px] text-sm">
                        <thead>
                            <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                <th className="px-3 py-2">Cuenta</th>
                                <th className="px-3 py-2">Dijo ser</th>
                                <th className="px-3 py-2">Motivo</th>
                                <th className="px-3 py-2">Recibida</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {pending.map((row) => (
                                <tr key={row.id} className="align-top">
                                    <td className="px-3 py-2">
                                        <p className="font-bold">
                                            {row.account_name}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            {row.email}
                                        </p>
                                        <p className="mt-1 text-xs">
                                            <span
                                                className={badge(
                                                    row.kind === 'client'
                                                        ? 'blue'
                                                        : 'slate',
                                                )}
                                            >
                                                {row.kind === 'client'
                                                    ? 'Cliente'
                                                    : 'Colaborador'}
                                            </span>{' '}
                                            {row.office}
                                        </p>
                                    </td>
                                    <td className="px-3 py-2">
                                        <p>{row.claimed_name}</p>
                                        <span
                                            className={badge(
                                                row.name_matches
                                                    ? 'green'
                                                    : 'amber',
                                            )}
                                        >
                                            {row.name_matches
                                                ? 'Coincide con la cuenta'
                                                : 'No coincide con la cuenta'}
                                        </span>
                                    </td>
                                    <td className="max-w-xs px-3 py-2 text-slate-700">
                                        {row.reason}
                                        {row.ip && (
                                            <p className="mt-1 text-[11px] text-slate-400">
                                                IP {row.ip}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {relativeTime(row.requested_at)}
                                    </td>
                                    <td className="space-x-2 px-3 py-2 text-right whitespace-nowrap">
                                        <button
                                            className={primaryButton}
                                            onClick={() => setApproving(row)}
                                        >
                                            <Check className="size-4" /> Aprobar
                                        </button>
                                        <button
                                            className={secondaryButton}
                                            onClick={() => setRejecting(row)}
                                        >
                                            <X className="size-4" /> Rechazar
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {pending.length === 0 && (
                                <EmptyRow
                                    colSpan={5}
                                    text="No hay solicitudes por resolver."
                                />
                            )}
                        </tbody>
                    </table>
                </section>

                {history.length > 0 && (
                    <section className={`${panel} overflow-x-auto p-4`}>
                        <SectionHeader title="Historial" />
                        <table className="w-full min-w-[720px] text-sm">
                            <tbody className="divide-y divide-slate-100">
                                {history.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-3 py-2">
                                            <p className="font-bold">
                                                {row.account_name}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {row.email}
                                            </p>
                                        </td>
                                        <td className="px-3 py-2">
                                            {formatDate(row.requested_at)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span
                                                className={badge(
                                                    statusBadge[row.status][1],
                                                )}
                                            >
                                                {statusBadge[row.status][0]}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-slate-600">
                                            {row.note ?? ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}
            </Shell>

            <ConfirmDialog
                open={approving !== null}
                icon={<ShieldCheck className="size-7" />}
                title="¿Aprobar el cambio de contraseña?"
                description={
                    <>
                        Se generará una contraseña temporal para{' '}
                        <strong>{approving?.account_name}</strong>, se cerrarán
                        sus sesiones abiertas y se la enviaremos a{' '}
                        <strong>{approving?.email}</strong>. Al ingresar tendrá
                        que crear una contraseña nueva.
                    </>
                }
                confirmLabel="Sí, aprobar"
                processing={sending}
                onConfirm={() => approving && approve(approving)}
                onClose={() => setApproving(null)}
            >
                {approving && !approving.name_matches && (
                    <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        Ojo: el nombre que escribió (
                        <strong>{approving.claimed_name}</strong>) no coincide
                        con el de la cuenta. Confirma que es la persona antes de
                        aprobar.
                    </p>
                )}
            </ConfirmDialog>
            <ConfirmDialog
                open={rejecting !== null}
                tone="danger"
                icon={<ShieldX className="size-7" />}
                title="Rechazar la solicitud"
                description={
                    <>
                        <strong>{rejecting?.account_name}</strong> recibirá un
                        correo avisando que su solicitud no fue aprobada. Su
                        contraseña actual no cambia.
                    </>
                }
                noteLabel="Motivo (se envía a la persona)"
                noteRequired
                confirmLabel="Rechazar solicitud"
                processing={sending}
                onConfirm={(note) => rejecting && reject(rejecting, note)}
                onClose={() => setRejecting(null)}
            />

            <Dialog
                open={visibleCredentials !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCopied(false);
                        setDismissed(credentials ?? null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogTitle>Solicitud aprobada</DialogTitle>
                    <DialogDescription>
                        {visibleCredentials?.password
                            ? `Enviamos la contraseña temporal al correo de ${visibleCredentials?.name}. Por si el correo no llega, aquí la tienes; solo se muestra ahora. Al ingresar deberá crear una contraseña nueva.`
                            : `Enviamos la contraseña temporal únicamente al correo de ${visibleCredentials?.name}. Por seguridad no se muestra aquí. Al ingresar deberá crear una contraseña nueva.`}
                    </DialogDescription>
                    <dl className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                        <div>
                            <dt className="text-xs font-bold text-slate-500 uppercase">
                                Usuario
                            </dt>
                            <dd className="font-mono font-bold break-all">
                                {visibleCredentials?.email}
                            </dd>
                        </div>
                        {visibleCredentials?.password && (
                            <div>
                                <dt className="flex items-center gap-1 text-xs font-bold text-slate-500 uppercase">
                                    <KeyRound className="size-3.5" /> Contraseña
                                    temporal
                                </dt>
                                <dd className="font-mono text-xl font-black tracking-wider">
                                    {visibleCredentials.password}
                                </dd>
                            </div>
                        )}
                    </dl>
                    <div className="flex justify-end gap-2">
                        {visibleCredentials?.password && (
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() => {
                                    void navigator.clipboard
                                        ?.writeText(
                                            `Usuario: ${visibleCredentials.email}\nContraseña temporal: ${visibleCredentials.password}`,
                                        )
                                        .then(() => setCopied(true));
                                }}
                            >
                                {copied ? 'Copiado' : 'Copiar datos'}
                            </button>
                        )}
                        <button
                            className={primaryButton}
                            onClick={() => setDismissed(credentials ?? null)}
                        >
                            Listo
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
