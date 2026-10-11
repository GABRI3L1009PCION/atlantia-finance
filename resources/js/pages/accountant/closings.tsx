import { Head, usePage } from '@inertiajs/react';
import { CheckCircle2, Lock, RotateCcw, Send, XCircle } from 'lucide-react';
import { useState } from 'react';
import type {
    AccountantCompany,
    AccountantContext,
    AccountantPermissions,
} from '@/components/accountant/kit';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Card,
    Table,
    post,
    statusTone,
    toneBadge,
} from '@/components/supervisor/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Closing = AccountantCompany & {
    close: {
        checks: { label: string; ok: boolean; detail: string }[];
        can_close: boolean;
        company: string;
        period: string;
    } | null;
    close_error: string | null;
    close_requested: boolean;
    reopen_requested: boolean;
};
type Mode = 'prepare' | 'close' | 'reopen';

export default function AccountantClosings({
    companies,
    permissions,
    notice,
}: {
    context: AccountantContext;
    companies: Closing[];
    permissions: AccountantPermissions;
    notice: string | null;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [selected, setSelected] = useState<{
        company: Closing;
        mode: Mode;
    } | null>(null);
    const [text, setText] = useState('');
    const open = (company: Closing, mode: Mode) => {
        setText('');
        setSelected({ company, mode });
    };
    const submit = () => {
        if (!selected || selected.company.period_id === null) {
            return;
        }

        const base = `/accountant/companies/${selected.company.id}/periods/${selected.company.period_id}`;
        const target =
            selected.mode === 'prepare'
                ? `${base}/request-close`
                : selected.mode === 'close'
                  ? `${base}/close`
                  : `${base}/request-reopen`;
        post(
            target,
            selected.mode === 'reopen' ? { reason: text } : { note: text },
            () => setSelected(null),
        );
    };

    return (
        <>
            <Head title="Cierres" />
            <Shell>
                <Notice message={notice} />
                {Object.values(errors).length > 0 && (
                    <p className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <PageHeader
                    title="Cierres"
                    subtitle="Prepara el cierre con su checklist, solicítalo a quien cierra, y pide reapertura con motivo cuando sea necesario"
                />
                <Card title={`${companies.length} empresa(s)`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Período',
                            'Estado',
                            'Checklist de cierre',
                            'Solicitudes',
                            'Acciones',
                        ]}
                        empty={
                            companies.length === 0 && 'Sin empresas a cargo.'
                        }
                    >
                        {companies.map((company) => (
                            <tr key={company.id} className="align-top">
                                <td className="px-2 py-2 font-bold">
                                    {company.name}
                                </td>
                                <td className="px-2 py-2">
                                    {company.period_name ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            statusTone[company.status] ??
                                                'slate',
                                        )}
                                    >
                                        {company.status_label}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    {company.close_error ? (
                                        <span className="text-red-700">
                                            {company.close_error}
                                        </span>
                                    ) : company.close ? (
                                        `${company.close.checks.filter((check) => check.ok).length} de ${company.close.checks.length} requisitos`
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-2 py-2 text-xs">
                                    {company.close_requested && (
                                        <p className="text-blue-700">
                                            Cierre solicitado
                                        </p>
                                    )}
                                    {company.reopen_requested && (
                                        <p className="text-violet-700">
                                            Reapertura solicitada
                                        </p>
                                    )}
                                    {!company.close_requested &&
                                        !company.reopen_requested &&
                                        '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        {company.status !== 'closed' &&
                                            company.close && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        open(company, 'prepare')
                                                    }
                                                >
                                                    <Send className="size-3.5" />{' '}
                                                    Preparar cierre
                                                </button>
                                            )}
                                        {company.status !== 'closed' &&
                                            company.close &&
                                            permissions.canClose && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        open(company, 'close')
                                                    }
                                                >
                                                    <Lock className="size-3.5" />{' '}
                                                    Cerrar
                                                </button>
                                            )}
                                        {company.status === 'closed' &&
                                            permissions.canRequestReopen &&
                                            !company.reopen_requested && (
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() =>
                                                        open(company, 'reopen')
                                                    }
                                                >
                                                    <RotateCcw className="size-3.5" />{' '}
                                                    Solicitar reapertura
                                                </button>
                                            )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>

            <Dialog
                open={selected !== null}
                onOpenChange={(value) => !value && setSelected(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {selected?.mode === 'prepare'
                                ? 'Preparar y solicitar cierre'
                                : selected?.mode === 'close'
                                  ? 'Cerrar período'
                                  : 'Solicitar reapertura'}
                        </DialogTitle>
                        <DialogDescription>
                            {selected?.company.name} ·{' '}
                            {selected?.company.period_name}.{' '}
                            {selected?.mode === 'close' &&
                                'Después de cerrar, cualquier modificación requerirá reapertura autorizada.'}
                            {selected?.mode === 'prepare' &&
                                'La solicitud se envía a quien cierra el período; requiere la revisión aprobada y el checklist sin bloqueantes.'}
                            {selected?.mode === 'reopen' &&
                                'El motivo es obligatorio y la reapertura queda pendiente de autorización.'}
                        </DialogDescription>
                    </DialogHeader>
                    {selected?.mode !== 'reopen' && selected?.company.close && (
                        <ul className="space-y-1.5 text-sm">
                            {selected.company.close.checks.map((check) => (
                                <li
                                    key={check.label}
                                    className="flex items-start gap-2"
                                >
                                    {check.ok ? (
                                        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                    ) : (
                                        <XCircle className="mt-0.5 size-4 shrink-0 text-red-600" />
                                    )}
                                    <span>
                                        <b>{check.label}.</b>{' '}
                                        <span className="text-[#526b92]">
                                            {check.detail}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {selected?.mode !== 'close' && (
                        <textarea
                            aria-label={
                                selected?.mode === 'reopen' ? 'Motivo' : 'Nota'
                            }
                            className={`${input} h-20 py-2`}
                            placeholder={
                                selected?.mode === 'reopen'
                                    ? 'Motivo de la reapertura'
                                    : 'Nota para quien cierra (opcional)'
                            }
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                        />
                    )}
                    <DialogFooter>
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() => setSelected(null)}
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            className={primaryButton}
                            disabled={
                                (selected?.mode === 'reopen' &&
                                    text.trim().length < 10) ||
                                (selected?.mode === 'close' &&
                                    !selected.company.close?.can_close)
                            }
                            onClick={submit}
                        >
                            {selected?.mode === 'prepare'
                                ? 'Solicitar cierre'
                                : selected?.mode === 'close'
                                  ? 'Cerrar período'
                                  : 'Solicitar reapertura'}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
