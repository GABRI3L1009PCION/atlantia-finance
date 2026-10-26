import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Loader2, ShieldCheck, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import {
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Preview = {
    id: number;
    name: string;
    tax_id: string;
    fel_certified: number;
    journal_entries: number;
    sales_invoices: number;
    purchases: number;
    reasons: Record<string, string>;
    trash_days: number;
};

export type TrashedCompany = {
    id: number;
    name: string;
    tax_id: string;
    reason: string;
    note: string | null;
    deleted_by: string;
    deleted_at: string;
    purge_after: string;
    days_left: number;
};

/**
 * The owner sends a client company to the trash: a reason, the company's name typed again and the password. The
 * server backs the company up first and keeps it restorable for the days the dialog states.
 */
export function DeleteCompanyDialog({
    company,
    onClose,
}: {
    company: { id: number; name: string } | null;
    onClose: () => void;
}) {
    const [preview, setPreview] = useState<{
        id: number;
        data: Preview | null;
    } | null>(null);
    const form = useForm({
        reason: '',
        note: '',
        confirm_name: '',
        password: '',
    });
    const companyId = company?.id;

    useEffect(() => {
        if (companyId === undefined) {
            return;
        }

        let cancelled = false;
        fetch(`/office/companies/${companyId}/deletion`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json() as Promise<Preview>)
            .then((data) => {
                if (!cancelled) {
                    setPreview({ id: companyId, data });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPreview({ id: companyId, data: null });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [companyId]);

    const details = preview?.id === companyId ? preview?.data : null;
    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (companyId === undefined) {
            return;
        }

        form.delete(`/office/companies/${companyId}`, {
            preserveScroll: true,
            onSuccess: close,
        });
    };
    const matches =
        company !== null &&
        form.data.confirm_name.trim().toLowerCase() ===
            company.name.trim().toLowerCase();

    return (
        <Dialog
            open={company !== null}
            onOpenChange={(open) => !open && close()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-[#640000]">
                        <Trash2 className="size-5" /> Eliminar empresa
                    </DialogTitle>
                    <DialogDescription>
                        {company?.name} irá a la papelera. Antes se guarda una
                        copia de seguridad completa y podrás restaurarla durante{' '}
                        {details?.trash_days ?? 30} días. Te avisaremos por
                        correo antes de que se elimine definitivamente.
                    </DialogDescription>
                </DialogHeader>

                {details === null || details === undefined ? (
                    <p className="flex items-center gap-2 text-sm text-slate-500">
                        <Loader2 className="size-4 animate-spin" /> Revisando la
                        información de la empresa…
                    </p>
                ) : (
                    <form onSubmit={submit} className="grid gap-3">
                        <div className="rounded-xl bg-[#faf6f4] p-3 text-sm text-[#4a5568]">
                            <p className="flex items-center gap-2 font-semibold text-[#172033]">
                                <ShieldCheck className="size-4 text-emerald-700" />{' '}
                                Se respaldará: {details.journal_entries}{' '}
                                asiento(s), {details.sales_invoices} venta(s) y{' '}
                                {details.purchases} compra(s), con todo lo demás
                                de la empresa.
                            </p>
                            {details.fel_certified > 0 && (
                                <p className="mt-2 flex items-start gap-2 text-amber-800">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    Tiene {details.fel_certified} factura(s) FEL
                                    certificada(s). Esas facturas siguen
                                    existiendo en la SAT aunque la empresa se
                                    elimine aquí.
                                </p>
                            )}
                        </div>

                        <label className="text-sm font-semibold">
                            Motivo
                            <select
                                required
                                className={`${input} mt-1`}
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                            >
                                <option value="">Elige el motivo</option>
                                {Object.entries(details.reasons).map(
                                    ([code, label]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                            {form.errors.reason && (
                                <span className="text-xs text-red-700">
                                    {form.errors.reason}
                                </span>
                            )}
                        </label>
                        <label className="text-sm font-semibold">
                            Comentario
                            {form.data.reason !== 'other' && ' (opcional)'}
                            <textarea
                                className={`${input} mt-1 min-h-16 py-2`}
                                maxLength={500}
                                required={form.data.reason === 'other'}
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                            />
                            {form.errors.note && (
                                <span className="text-xs text-red-700">
                                    {form.errors.note}
                                </span>
                            )}
                        </label>
                        <label className="text-sm font-semibold">
                            Para confirmar, escribe{' '}
                            <span className="text-[#640000]">
                                {company?.name}
                            </span>
                            <input
                                className={`${input} mt-1`}
                                autoComplete="off"
                                value={form.data.confirm_name}
                                onChange={(event) =>
                                    form.setData(
                                        'confirm_name',
                                        event.target.value,
                                    )
                                }
                            />
                            {form.errors.confirm_name && (
                                <span className="text-xs text-red-700">
                                    {form.errors.confirm_name}
                                </span>
                            )}
                        </label>
                        <label className="text-sm font-semibold">
                            Tu contraseña
                            <input
                                type="password"
                                required
                                autoComplete="current-password"
                                className={`${input} mt-1`}
                                value={form.data.password}
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                            />
                            {form.errors.password && (
                                <span className="text-xs text-red-700">
                                    {form.errors.password}
                                </span>
                            )}
                        </label>
                        {(form.errors as Record<string, string>).company && (
                            <p className="text-sm text-red-700">
                                {
                                    (form.errors as Record<string, string>)
                                        .company
                                }
                            </p>
                        )}
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={close}
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={
                                    form.processing ||
                                    !matches ||
                                    form.data.reason === ''
                                }
                            >
                                {form.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                Mover a la papelera
                            </button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Companies in the trash, each with the days left to restore it. */
export function CompanyTrash({ trash }: { trash: TrashedCompany[] }) {
    if (trash.length === 0) {
        return null;
    }

    return (
        <section className="mt-5 rounded-2xl border border-amber-200 bg-amber-50/40 p-5">
            <h2 className="flex items-center gap-2 text-lg font-bold text-[#061633]">
                <Trash2 className="size-5 text-[#640000]" /> Papelera (
                {trash.length})
            </h2>
            <p className="text-sm text-[#526b92]">
                Empresas eliminadas que todavía puedes restaurar con toda su
                información.
            </p>
            <div className="mt-3 overflow-x-auto">
                <table className="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr className="text-left text-xs text-[#526b92] uppercase">
                            <th className="px-3 py-2">Empresa</th>
                            <th className="px-3 py-2">Motivo</th>
                            <th className="px-3 py-2">Eliminada por</th>
                            <th className="px-3 py-2">Se elimina en</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {trash.map((row) => (
                            <tr
                                key={row.id}
                                className="border-t border-amber-100"
                            >
                                <td className="px-3 py-2">
                                    <p className="font-semibold text-[#061633]">
                                        {row.name}
                                    </p>
                                    <p className="text-xs text-[#526b92]">
                                        NIT {row.tax_id}
                                    </p>
                                </td>
                                <td className="px-3 py-2">
                                    {row.reason}
                                    {row.note && (
                                        <p className="text-xs text-[#526b92]">
                                            {row.note}
                                        </p>
                                    )}
                                </td>
                                <td className="px-3 py-2">
                                    {row.deleted_by}
                                    <p className="text-xs text-[#526b92]">
                                        {new Date(
                                            row.deleted_at,
                                        ).toLocaleString('es-GT')}
                                    </p>
                                </td>
                                <td
                                    className={`px-3 py-2 font-semibold ${row.days_left <= 7 ? 'text-red-700' : 'text-[#061633]'}`}
                                >
                                    {row.days_left} día(s)
                                    <p className="text-xs font-normal text-[#526b92]">
                                        {new Date(
                                            row.purge_after,
                                        ).toLocaleDateString('es-GT')}
                                    </p>
                                </td>
                                <td className="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() =>
                                            router.post(
                                                `/office/company-deletions/${row.id}/restore`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Restaurar
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
