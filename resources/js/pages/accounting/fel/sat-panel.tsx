import { useForm } from '@inertiajs/react';
import {
    Check,
    ClipboardCopy,
    ExternalLink,
    FileUp,
    Undo2,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';
import {
    Card,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { decimal } from './types';
import type { Document, StoredLine } from './types';

type Assignee = { id: number; name: string };

const base = (companyId: string, documentId: string) =>
    `/accounting/companies/${companyId}/fel/${documentId}/sat`;

function CopyRow({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
        } catch {
            const area = window.document.createElement('textarea');
            area.value = value;
            window.document.body.appendChild(area);
            area.select();
            window.document.execCommand('copy');
            area.remove();
        }

        setCopied(true);
        window.setTimeout(() => setCopied(false), 1500);
    };

    return (
        <div className="flex items-center gap-3 border-b border-slate-100 py-2 text-sm">
            <span className="w-44 shrink-0 text-slate-500">{label}</span>
            <span className="min-w-0 flex-1 font-semibold break-words">
                {value || '—'}
            </span>
            <button
                type="button"
                onClick={copy}
                disabled={value === ''}
                aria-label={`Copiar ${label}`}
                className="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 px-2.5 text-xs font-bold text-[#640000] hover:bg-amber-50 disabled:opacity-40"
            >
                {copied ? (
                    <>
                        <Check className="size-3.5" /> Copiado
                    </>
                ) : (
                    <>
                        <ClipboardCopy className="size-3.5" /> Copiar
                    </>
                )}
            </button>
        </div>
    );
}

/**
 * The SAT portal and the DTE work with prices that already include the IVA (price × quantity − discount = line total),
 * while Atlantia stores them without it. This brings the line to the SAT's way, using the line's own tax rate.
 */
function withVat(line: StoredLine): { unit: number; discount: number } {
    const taxable = line.subtotal_cents - line.discount_cents;
    const rate = taxable > 0 ? line.tax_cents / taxable : 0;
    const discount = Math.round(line.discount_cents * (1 + rate));
    const quantity = Number(line.quantity) || 1;

    return {
        unit: Math.round((line.total_cents + discount) / quantity),
        discount,
    };
}

function CopySheet({ doc }: { doc: Document }) {
    const type = doc.document_type === 'NCRE' ? 'Nota de crédito' : 'Factura';

    return (
        <div className="space-y-4">
            <p className="rounded-xl bg-amber-50 p-3 text-xs text-amber-900">
                Los campos van en el orden del formulario de la SAT. Copia cada
                uno y pégalo en el portal; al terminar, el total del portal debe
                ser {money(doc.total_cents)}.
            </p>
            <div>
                <p className="mb-1 text-xs font-bold tracking-wide text-slate-500 uppercase">
                    Documento
                </p>
                <CopyRow label="Tipo de documento" value={type} />
                <CopyRow
                    label="Fecha de emisión"
                    value={doc.issued_on.slice(0, 10)}
                />
                <CopyRow label="Moneda" value={doc.currency} />
            </div>
            <div>
                <p className="mb-1 text-xs font-bold tracking-wide text-slate-500 uppercase">
                    Receptor
                </p>
                <CopyRow label="NIT" value={doc.receiver_snapshot.nit} />
                <CopyRow label="Nombre" value={doc.receiver_snapshot.name} />
                <CopyRow
                    label="Dirección"
                    value={doc.receiver_snapshot.address}
                />
                <CopyRow
                    label="Correo"
                    value={doc.receiver_snapshot.email ?? ''}
                />
            </div>
            {doc.lines.map((line, index) => (
                <div key={line.id}>
                    <p className="mb-1 text-xs font-bold tracking-wide text-slate-500 uppercase">
                        Línea {index + 1}
                    </p>
                    <CopyRow
                        label="Bien o servicio"
                        value={
                            line.classification === 'goods'
                                ? 'Bien'
                                : 'Servicio'
                        }
                    />
                    <CopyRow label="Cantidad" value={String(line.quantity)} />
                    <CopyRow
                        label="Descripción"
                        value={`${line.item} - ${line.description}`}
                    />
                    <CopyRow
                        label="Precio unitario (con IVA)"
                        value={decimal(withVat(line).unit)}
                    />
                    <CopyRow
                        label="Descuento (con IVA)"
                        value={decimal(withVat(line).discount)}
                    />
                    <CopyRow
                        label="Total de la línea (con IVA)"
                        value={decimal(line.total_cents)}
                    />
                    <CopyRow
                        label="Monto gravable (sin IVA)"
                        value={decimal(
                            line.subtotal_cents - line.discount_cents,
                        )}
                    />
                    <CopyRow
                        label="IVA de la línea"
                        value={decimal(line.tax_cents)}
                    />
                </div>
            ))}
            <div>
                <p className="mb-1 text-xs font-bold tracking-wide text-slate-500 uppercase">
                    Totales
                </p>
                <CopyRow label="IVA" value={decimal(doc.tax_cents)} />
                <CopyRow label="Gran total" value={decimal(doc.total_cents)} />
                {doc.observations && (
                    <CopyRow label="Observaciones" value={doc.observations} />
                )}
            </div>
        </div>
    );
}

function RegisterForm({ url, onDone }: { url: string; onDone: () => void }) {
    const [mode, setMode] = useState<'xml' | 'manual'>('xml');
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    const form = useForm({
        sat_xml: null as File | null,
        authorization_number: '',
        fel_series: '',
        fel_number: '',
        certified_at: now.toISOString().slice(0, 16),
    });
    const errors = form.errors as Record<string, string>;
    const tab = (value: 'xml' | 'manual', label: string) => (
        <button
            type="button"
            onClick={() => setMode(value)}
            aria-pressed={mode === value}
            className={`h-10 flex-1 rounded-lg text-sm font-bold ${mode === value ? 'bg-[#640000] text-white' : 'bg-slate-100 text-slate-700'}`}
        >
            {label}
        </button>
    );

    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) =>
                    mode === 'xml'
                        ? { sat_xml: data.sat_xml }
                        : {
                              authorization_number: data.authorization_number,
                              fel_series: data.fel_series,
                              fel_number: data.fel_number,
                              certified_at: data.certified_at.replace('T', ' '),
                          },
                );
                form.post(url, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <div className="flex gap-2">
                {tab('xml', 'Subir el XML de la SAT')}
                {tab('manual', 'Escribir los datos')}
            </div>
            {mode === 'xml' ? (
                <label className="block text-sm">
                    XML de la factura emitida
                    <span className="mt-1 flex h-12 cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-400 px-3 hover:bg-amber-50">
                        <FileUp className="size-5 text-[#640000]" />
                        <span className="truncate">
                            {form.data.sat_xml?.name ??
                                'Seleccionar archivo .xml'}
                        </span>
                        <input
                            type="file"
                            accept=".xml,text/xml,application/xml"
                            className="sr-only"
                            onChange={(event) =>
                                form.setData(
                                    'sat_xml',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </span>
                    <span className="mt-1 block text-xs text-slate-500">
                        Atlantia lee la autorización, serie, número y fecha, y
                        comprueba que el NIT, el receptor y el total coincidan
                        con este documento.
                    </span>
                    {errors.sat_xml && (
                        <span className="mt-1 block text-xs text-red-700">
                            {errors.sat_xml}
                        </span>
                    )}
                </label>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-sm sm:col-span-2">
                        Número de autorización
                        <input
                            className={field}
                            value={form.data.authorization_number}
                            onChange={(event) =>
                                form.setData(
                                    'authorization_number',
                                    event.target.value,
                                )
                            }
                            required
                        />
                        {errors.authorization_number && (
                            <span className="text-xs text-red-700">
                                {errors.authorization_number}
                            </span>
                        )}
                    </label>
                    <label className="text-sm">
                        Serie
                        <input
                            className={field}
                            value={form.data.fel_series}
                            onChange={(event) =>
                                form.setData('fel_series', event.target.value)
                            }
                            required
                        />
                        {errors.fel_series && (
                            <span className="text-xs text-red-700">
                                {errors.fel_series}
                            </span>
                        )}
                    </label>
                    <label className="text-sm">
                        Número
                        <input
                            className={field}
                            value={form.data.fel_number}
                            onChange={(event) =>
                                form.setData('fel_number', event.target.value)
                            }
                            required
                        />
                        {errors.fel_number && (
                            <span className="text-xs text-red-700">
                                {errors.fel_number}
                            </span>
                        )}
                    </label>
                    <label className="text-sm sm:col-span-2">
                        Fecha y hora de emisión
                        <input
                            className={field}
                            type="datetime-local"
                            value={form.data.certified_at}
                            onChange={(event) =>
                                form.setData('certified_at', event.target.value)
                            }
                            required
                        />
                        {errors.certified_at && (
                            <span className="text-xs text-red-700">
                                {errors.certified_at}
                            </span>
                        )}
                    </label>
                </div>
            )}
            {errors.document && (
                <p className="text-xs text-red-700">{errors.document}</p>
            )}
            <div className="flex justify-end">
                <button
                    className={primary}
                    disabled={
                        form.processing ||
                        (mode === 'xml' && !form.data.sat_xml)
                    }
                >
                    Registrar emisión
                </button>
            </div>
        </form>
    );
}

/** A document prepared to be issued by hand in the SAT portal: the steps to finish it and register the result. */
export function SatPanel({
    companyId,
    doc,
    canWrite,
    canCertify,
    portalUrl,
    assignees,
}: {
    companyId: string;
    doc: Document;
    canWrite: boolean;
    canCertify: boolean;
    portalUrl: string;
    assignees: Assignee[];
}) {
    const [modal, setModal] = useState<'copy' | 'register' | 'assign' | null>(
        null,
    );
    const [now] = useState(() => Date.now());
    const url = base(companyId, doc.public_id);
    const back = useForm({});
    const assign = useForm({
        user_id: assignees[0] ? String(assignees[0].id) : '',
    });
    const days = doc.awaiting_sat_at
        ? Math.floor(
              (now - new Date(doc.awaiting_sat_at).getTime()) / 86_400_000,
          )
        : 0;

    return (
        <>
            <Card title="Pendiente de emitir en la SAT">
                <p className="text-sm text-slate-600">
                    Este documento está listo. Emítelo en el portal de la SAT
                    con tu usuario y registra aquí lo que la SAT emitió.
                    {days >= 1 && (
                        <b className="ml-1 text-amber-800">
                            Lleva {days} día{days === 1 ? '' : 's'} esperando.
                        </b>
                    )}
                </p>
                <ol className="mt-4 grid gap-3 text-sm md:grid-cols-3">
                    <li className="rounded-xl border border-slate-200 p-4">
                        <p className="font-bold">1. Llévalo al portal</p>
                        <p className="mt-1 text-xs text-slate-500">
                            Abre el portal de la SAT y copia los datos campo por
                            campo.
                        </p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <a
                                className={secondary}
                                href={portalUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink className="mr-1 inline size-4" />
                                Abrir portal SAT
                            </a>
                            <button
                                type="button"
                                className={secondary}
                                onClick={() => setModal('copy')}
                            >
                                <ClipboardCopy className="mr-1 inline size-4" />
                                Copiar datos
                            </button>
                        </div>
                    </li>
                    <li className="rounded-xl border border-slate-200 p-4">
                        <p className="font-bold">2. Emítelo en la SAT</p>
                        <p className="mt-1 text-xs text-slate-500">
                            Emite la factura con tu usuario de la SAT. Atlantia
                            nunca pide ni guarda tu clave.
                        </p>
                    </li>
                    <li className="rounded-xl border border-slate-200 p-4">
                        <p className="font-bold">3. Regístralo aquí</p>
                        <p className="mt-1 text-xs text-slate-500">
                            Sube el XML que descargas de la SAT, o escribe los
                            datos de autorización.
                        </p>
                        {canWrite && (
                            <button
                                type="button"
                                className={`${primary} mt-3`}
                                onClick={() => setModal('register')}
                            >
                                Registrar emisión
                            </button>
                        )}
                    </li>
                </ol>
                {canWrite && (
                    <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                        {canCertify && assignees.length > 0 && (
                            <button
                                type="button"
                                className={secondary}
                                onClick={() => setModal('assign')}
                            >
                                <UserCheck className="mr-1 inline size-4" />
                                Asignar a un auxiliar
                            </button>
                        )}
                        <button
                            type="button"
                            className={secondary}
                            disabled={back.processing}
                            onClick={() =>
                                confirm(
                                    '¿Devolver el documento a borrador para corregirlo?',
                                ) &&
                                back.post(`${url}/return`, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Undo2 className="mr-1 inline size-4" />
                            Devolver a borrador
                        </button>
                    </div>
                )}
            </Card>

            <Dialog
                open={modal === 'copy'}
                onOpenChange={(open) => !open && setModal(null)}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-3xl">
                    <DialogTitle>
                        Copiar datos para el portal de la SAT
                    </DialogTitle>
                    <DialogDescription>
                        Documento {doc.series}-{doc.number} ·{' '}
                        {money(doc.total_cents)}
                    </DialogDescription>
                    <CopySheet doc={doc} />
                </DialogContent>
            </Dialog>

            <Dialog
                open={modal === 'register'}
                onOpenChange={(open) => !open && setModal(null)}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-xl">
                    <DialogTitle>Registrar lo que la SAT emitió</DialogTitle>
                    <DialogDescription>
                        Con esto el documento queda como emitido, se contabiliza
                        y puedes enviarlo al cliente.
                    </DialogDescription>
                    <RegisterForm
                        url={`${url}/register`}
                        onDone={() => setModal(null)}
                    />
                </DialogContent>
            </Dialog>

            <Dialog
                open={modal === 'assign'}
                onOpenChange={(open) => !open && setModal(null)}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-md">
                    <DialogTitle>Asignar a un auxiliar</DialogTitle>
                    <DialogDescription>
                        Recibirá una tarea para emitir este documento en la SAT
                        y registrarlo aquí.
                    </DialogDescription>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            assign.post(`${url}/assign`, {
                                preserveScroll: true,
                                onSuccess: () => setModal(null),
                            });
                        }}
                    >
                        <select
                            className={field}
                            value={assign.data.user_id}
                            onChange={(event) =>
                                assign.setData('user_id', event.target.value)
                            }
                        >
                            {assignees.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </select>
                        <div className="flex justify-end">
                            <button
                                className={primary}
                                disabled={assign.processing}
                            >
                                Asignar tarea
                            </button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
