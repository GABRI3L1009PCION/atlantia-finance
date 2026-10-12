import { Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Download,
    FileCheck2,
    FileMinus2,
    FileText,
    Link2,
    Paperclip,
    RotateCcw,
    ShieldCheck,
    Trash2,
    WalletCards,
    XCircle,
} from 'lucide-react';
import AccountingSection from '@/components/accounting-section';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
} from '@/components/accounting-ui';
import * as routes from '@/routes/accounting';

type PurchaseDocument = {
    public_id: string;
    number: number | null;
    document_type: string;
    external_reference: string | null;
    status: 'draft' | 'approved' | 'void';
    document_date: string;
    due_on: string;
    total_cents: number;
    supplier_name: string;
    supplier_tax_id: string | null;
    void_reason: string | null;
};
type Line = {
    id: number;
    description: string;
    quantity: number;
    unit_cents: number;
    account_code: string;
    account_name: string;
};
type Payment = {
    id: number;
    amount_cents: number;
    paid_on: string;
    reference: string;
    voided_at: string | null;
    void_reason: string | null;
};
type CreditNoteRow = {
    id: number;
    fiscal_series: string | null;
    fiscal_number: string | null;
    issued_on: string;
    amount_cents: number;
    vat_cents: number;
    reason: string;
    status: string;
    void_reason: string | null;
};
type PayableSummary = {
    currency: string;
    exchange_rate: string;
    foreign_original_cents: number | null;
    credited_cents: number;
    advances_cents: number;
    adjusted_cents: number;
    outstanding_cents: number;
    committed_cents: number;
};
type FileRow = {
    public_id: string;
    original_name: string;
    mime_type: string;
    size_bytes: number;
};
type SourceEntry = {
    id: number;
    source_type: string;
    event: string;
    entry_public_id: string;
    entry_status: string;
    entry_number: number | null;
};

export default function Purchase({
    company,
    document,
    lines,
    payments,
    creditNotes,
    payable,
    canCredit,
    files,
    paid,
    sourceEntries,
    canWrite,
    isOwner,
    today,
    notice,
}: {
    company: Company;
    document: PurchaseDocument;
    lines: Line[];
    payments: Payment[];
    creditNotes: CreditNoteRow[];
    payable: PayableSummary | null;
    canCredit: boolean;
    files: FileRow[];
    paid: number;
    sourceEntries: SourceEntry[];
    canWrite: boolean;
    isOwner: boolean;
    today: string;
    notice?: string;
}) {
    const balance =
        document.status === 'void'
            ? 0
            : (payable?.outstanding_cents ??
              Math.max(0, Number(document.total_cents) - Number(paid)));
    const creditable = payable
        ? payable.outstanding_cents - payable.committed_cents
        : 0;
    const creditNote = useForm({
        amount: '',
        issued_on: today,
        reason: '',
        fiscal_series: '',
        fiscal_number: '',
    });
    const voidCreditNote = (id: number) => {
        const reason = window.prompt('Motivo de la anulación de la nota:');

        if (reason) {
            router.post(
                routes.purchaseVoidCreditNote.url([
                    company.public_id,
                    document.public_id,
                    id,
                ]),
                { reason },
                { preserveScroll: true },
            );
        }
    };
    const payment = useForm({
        request_id: crypto.randomUUID(),
        amount: '',
        paid_on: today,
        reference: '',
    });
    const attachment = useForm<{ file: File | null }>({ file: null });
    const statusLabel =
        document.status === 'draft'
            ? 'Borrador'
            : document.status === 'void'
              ? 'Anulado'
              : balance === 0
                ? 'Pagado'
                : paid > 0
                  ? 'Pago parcial'
                  : document.due_on < today
                    ? 'Vencido'
                    : 'Pendiente';

    const approve = () =>
        router.post(
            routes.purchaseProcess.url([company.public_id, document.public_id]),
            { action: 'approve' },
            { preserveScroll: true },
        );
    const voidDocument = () => {
        const reason = window.prompt(
            'Motivo de la anulación (quedará en auditoría):',
        );

        if (reason) {
            router.post(
                routes.purchaseProcess.url([
                    company.public_id,
                    document.public_id,
                ]),
                { action: 'void', reason },
                { preserveScroll: true },
            );
        }
    };
    const voidPayment = (id: number) => {
        const reason = window.prompt('Motivo de la anulación del pago:');

        if (reason) {
            router.post(
                routes.purchasePaymentVoid.url([
                    company.public_id,
                    document.public_id,
                    id,
                ]),
                { reason },
                { preserveScroll: true },
            );
        }
    };

    return (
        <AccountingSection
            company={company}
            title={
                document.number
                    ? `Compra interna CP-${String(document.number).padStart(6, '0')}`
                    : 'Borrador de compra'
            }
            action={
                <Link
                    href={routes.purchases(company.public_id)}
                    className="inline-flex items-center gap-2 rounded-xl border border-white/30 bg-white/10 px-4 py-3 text-sm font-semibold text-white hover:bg-white/20"
                >
                    <ArrowLeft className="size-4" /> Volver a compras
                </Link>
            }
        >
            {notice && (
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
                    {notice}
                </div>
            )}
            <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <Card title="Detalle del documento">
                        <div className="mb-5 grid gap-4 rounded-2xl bg-[#fbf7f7] p-5 sm:grid-cols-3">
                            <div>
                                <p className="text-xs font-semibold text-slate-500 uppercase">
                                    Proveedor
                                </p>
                                <p className="mt-1 font-semibold">
                                    {document.supplier_name}
                                </p>
                                <p className="text-sm text-slate-500">
                                    NIT{' '}
                                    {document.supplier_tax_id || 'No indicado'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold text-slate-500 uppercase">
                                    Referencia
                                </p>
                                <p className="mt-1 font-semibold">
                                    {document.external_reference ||
                                        'Sin referencia'}
                                </p>
                                <p className="text-sm text-slate-500">
                                    {document.document_type === 'expense'
                                        ? 'Gasto'
                                        : 'Compra'}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-semibold text-slate-500 uppercase">
                                    Fechas
                                </p>
                                <p className="mt-1">
                                    Documento: {document.document_date}
                                </p>
                                <p className="text-sm text-slate-500">
                                    Vence: {document.due_on}
                                </p>
                                {payable &&
                                    payable.currency !== 'GTQ' &&
                                    payable.foreign_original_cents !== null && (
                                        <p className="text-xs text-slate-500">
                                            {payable.currency}{' '}
                                            {(
                                                payable.foreign_original_cents /
                                                100
                                            ).toFixed(2)}{' '}
                                            a tipo de cambio{' '}
                                            {Number(payable.exchange_rate)}{' '}
                                            (montos en GTQ)
                                        </p>
                                    )}
                            </div>
                        </div>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[650px] text-left text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="p-4">Concepto</th>
                                        <th>Cuenta</th>
                                        <th className="text-right">Cantidad</th>
                                        <th className="text-right">Precio</th>
                                        <th className="p-4 text-right">
                                            Importe
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {lines.map((line) => (
                                        <tr key={line.id}>
                                            <td className="p-4 font-medium">
                                                {line.description}
                                            </td>
                                            <td>
                                                {line.account_code} ·{' '}
                                                {line.account_name}
                                            </td>
                                            <td className="text-right">
                                                {line.quantity}
                                            </td>
                                            <td className="text-right">
                                                {money(line.unit_cents)}
                                            </td>
                                            <td className="p-4 text-right font-semibold">
                                                {money(
                                                    line.quantity *
                                                        line.unit_cents,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot className="border-t-2 bg-[#fbf7f7]">
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="p-4 text-right font-semibold"
                                        >
                                            Total
                                        </td>
                                        <td className="p-4 text-right text-lg font-semibold text-[#640000]">
                                            {money(document.total_cents)}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        {document.status === 'void' && (
                            <div className="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                                <strong>Documento anulado.</strong>{' '}
                                {document.void_reason}
                            </div>
                        )}
                    </Card>

                    <Card title="Documentos de respaldo">
                        <p className="mb-4 text-sm text-slate-500">
                            Los archivos se guardan fuera del directorio
                            público, con huella SHA-256 y acceso autorizado. Al
                            aprobar el documento quedan inmutables.
                        </p>
                        {document.status === 'draft' && canWrite && (
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    attachment.post(
                                        routes.purchaseFileStore.url([
                                            company.public_id,
                                            document.public_id,
                                        ]),
                                        {
                                            forceFormData: true,
                                            preserveScroll: true,
                                            onSuccess: () => attachment.reset(),
                                        },
                                    );
                                }}
                                className="flex flex-wrap items-end gap-3 rounded-xl border border-dashed p-4"
                            >
                                <label className="min-w-[260px] flex-1 text-sm font-medium">
                                    Archivo PDF, imagen u hoja de cálculo
                                    <input
                                        type="file"
                                        className={`${field} file:mr-3 file:rounded-lg file:border-0 file:bg-[#f5e7e7] file:px-3 file:py-1 file:text-[#640000]`}
                                        onChange={(event) =>
                                            attachment.setData(
                                                'file',
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </label>
                                <button
                                    className={primary}
                                    disabled={
                                        !attachment.data.file ||
                                        attachment.processing
                                    }
                                >
                                    <Paperclip className="size-4" /> Adjuntar
                                </button>
                                <Errors errors={attachment.errors} />
                            </form>
                        )}
                        <div className="mt-4 space-y-2">
                            {files.map((file) => (
                                <div
                                    key={file.public_id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                                >
                                    <span className="flex items-center gap-3">
                                        <FileText className="size-5 text-[#7d0808]" />
                                        <span>
                                            <strong>
                                                {file.original_name}
                                            </strong>
                                            <small className="block text-slate-500">
                                                {(
                                                    file.size_bytes / 1024
                                                ).toFixed(1)}{' '}
                                                KB · {file.mime_type}
                                            </small>
                                        </span>
                                    </span>
                                    <span className="flex gap-2">
                                        <a
                                            href={routes.purchaseFileDownload.url(
                                                [
                                                    company.public_id,
                                                    document.public_id,
                                                    file.public_id,
                                                ],
                                            )}
                                            className={secondary}
                                        >
                                            <Download className="size-4" />{' '}
                                            Descargar
                                        </a>
                                        {document.status === 'draft' &&
                                            canWrite && (
                                                <button
                                                    type="button"
                                                    className={secondary}
                                                    onClick={() =>
                                                        router.delete(
                                                            routes.purchaseFileDestroy.url(
                                                                [
                                                                    company.public_id,
                                                                    document.public_id,
                                                                    file.public_id,
                                                                ],
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />{' '}
                                                    Retirar
                                                </button>
                                            )}
                                    </span>
                                </div>
                            ))}
                            {!files.length && (
                                <p className="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-500">
                                    Sin respaldos adjuntos.
                                </p>
                            )}
                        </div>
                    </Card>

                    <Card title="Pagos al proveedor">
                        {document.status === 'approved' &&
                            balance > 0 &&
                            canWrite && (
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        payment.post(
                                            routes.purchasePayment.url([
                                                company.public_id,
                                                document.public_id,
                                            ]),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    payment.reset(
                                                        'amount',
                                                        'reference',
                                                    ),
                                            },
                                        );
                                    }}
                                    className="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3"
                                >
                                    <label>
                                        Monto
                                        <input
                                            inputMode="decimal"
                                            className={field}
                                            value={payment.data.amount}
                                            onChange={(event) =>
                                                payment.setData(
                                                    'amount',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder={(
                                                balance / 100
                                            ).toFixed(2)}
                                        />
                                    </label>
                                    <label>
                                        Fecha
                                        <input
                                            type="date"
                                            className={field}
                                            value={payment.data.paid_on}
                                            onChange={(event) =>
                                                payment.setData(
                                                    'paid_on',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label>
                                        Referencia
                                        <input
                                            className={field}
                                            value={payment.data.reference}
                                            onChange={(event) =>
                                                payment.setData(
                                                    'reference',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Transferencia o cheque"
                                        />
                                    </label>
                                    <div className="sm:col-span-3">
                                        <Errors errors={payment.errors} />
                                        <button
                                            className={primary}
                                            disabled={payment.processing}
                                        >
                                            <WalletCards className="size-4" />{' '}
                                            Registrar pago
                                        </button>
                                    </div>
                                </form>
                            )}
                        <div className="mt-4 space-y-2">
                            {payments.map((row) => (
                                <div
                                    key={row.id}
                                    className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 ${row.voided_at ? 'bg-slate-50 opacity-70' : ''}`}
                                >
                                    <div>
                                        <strong>
                                            {money(row.amount_cents)}
                                        </strong>
                                        <p className="text-sm text-slate-500">
                                            {row.paid_on} · {row.reference}
                                        </p>
                                        {row.voided_at && (
                                            <p className="text-xs text-red-600">
                                                Anulado: {row.void_reason}
                                            </p>
                                        )}
                                    </div>
                                    {!row.voided_at && isOwner && (
                                        <button
                                            type="button"
                                            className={secondary}
                                            onClick={() => voidPayment(row.id)}
                                        >
                                            <RotateCcw className="size-4" />{' '}
                                            Anular pago
                                        </button>
                                    )}
                                </div>
                            ))}
                            {!payments.length && (
                                <p className="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-500">
                                    Todavía no hay pagos registrados.
                                </p>
                            )}
                        </div>
                    </Card>

                    {document.status !== 'draft' && (
                        <Card title="Notas de crédito del proveedor">
                            {document.status === 'approved' &&
                                canCredit &&
                                creditable > 0 && (
                                    <form
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            creditNote.post(
                                                routes.purchaseCreditNote.url([
                                                    company.public_id,
                                                    document.public_id,
                                                ]),
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        creditNote.reset(
                                                            'amount',
                                                            'reason',
                                                            'fiscal_series',
                                                            'fiscal_number',
                                                        ),
                                                },
                                            );
                                        }}
                                        className="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3"
                                    >
                                        <label>
                                            Monto (máx. {money(creditable)})
                                            <input
                                                inputMode="decimal"
                                                className={field}
                                                value={creditNote.data.amount}
                                                onChange={(event) =>
                                                    creditNote.setData(
                                                        'amount',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label>
                                            Fecha
                                            <input
                                                type="date"
                                                className={field}
                                                value={
                                                    creditNote.data.issued_on
                                                }
                                                onChange={(event) =>
                                                    creditNote.setData(
                                                        'issued_on',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label>
                                            Serie / número fiscal
                                            <span className="flex gap-2">
                                                <input
                                                    className={field}
                                                    placeholder="Serie"
                                                    value={
                                                        creditNote.data
                                                            .fiscal_series
                                                    }
                                                    onChange={(event) =>
                                                        creditNote.setData(
                                                            'fiscal_series',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <input
                                                    className={field}
                                                    placeholder="Número"
                                                    value={
                                                        creditNote.data
                                                            .fiscal_number
                                                    }
                                                    onChange={(event) =>
                                                        creditNote.setData(
                                                            'fiscal_number',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </span>
                                        </label>
                                        <label className="sm:col-span-3">
                                            Motivo
                                            <input
                                                className={field}
                                                value={creditNote.data.reason}
                                                onChange={(event) =>
                                                    creditNote.setData(
                                                        'reason',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Devolución, descuento, error de precio…"
                                            />
                                        </label>
                                        <div className="sm:col-span-3">
                                            <Errors
                                                errors={creditNote.errors}
                                            />
                                            <button
                                                className={primary}
                                                disabled={creditNote.processing}
                                            >
                                                <FileMinus2 className="size-4" />{' '}
                                                Registrar nota de crédito
                                            </button>
                                        </div>
                                    </form>
                                )}
                            <div className="mt-4 space-y-2">
                                {creditNotes.map((note) => (
                                    <div
                                        key={note.id}
                                        className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 ${note.status !== 'issued' ? 'bg-slate-50 opacity-70' : ''}`}
                                    >
                                        <div>
                                            <strong>
                                                {money(note.amount_cents)}
                                            </strong>{' '}
                                            <span className="text-sm text-slate-500">
                                                (IVA {money(note.vat_cents)})
                                            </span>
                                            <p className="text-sm text-slate-500">
                                                {note.issued_on} ·{' '}
                                                {[
                                                    note.fiscal_series,
                                                    note.fiscal_number,
                                                ]
                                                    .filter(Boolean)
                                                    .join('-') ||
                                                    `NC #${note.id}`}{' '}
                                                · {note.reason}
                                            </p>
                                            {note.status !== 'issued' && (
                                                <p className="text-xs text-red-600">
                                                    Anulada: {note.void_reason}
                                                </p>
                                            )}
                                        </div>
                                        {note.status === 'issued' &&
                                            isOwner && (
                                                <button
                                                    type="button"
                                                    className={secondary}
                                                    onClick={() =>
                                                        voidCreditNote(note.id)
                                                    }
                                                >
                                                    <RotateCcw className="size-4" />{' '}
                                                    Anular nota
                                                </button>
                                            )}
                                    </div>
                                ))}
                                {!creditNotes.length && (
                                    <p className="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-500">
                                        Sin notas de crédito del proveedor.
                                    </p>
                                )}
                            </div>
                        </Card>
                    )}

                    <Card title="Trazabilidad contable">
                        <p className="mb-4 text-sm text-slate-500">
                            Cada evento financiero queda vinculado al asiento
                            que debe revisar y aprobar el propietario.
                        </p>
                        <div className="space-y-2">
                            {sourceEntries.map((entry) => (
                                <div
                                    key={entry.id}
                                    className="flex items-center justify-between rounded-xl border p-4"
                                >
                                    <span className="flex items-center gap-3">
                                        <Link2 className="size-5 text-[#7d0808]" />
                                        <span>
                                            <strong>
                                                {entry.source_type ===
                                                'purchase_payment'
                                                    ? 'Pago a proveedor'
                                                    : 'Compra o gasto'}{' '}
                                                ·{' '}
                                                {entry.event === 'reversal'
                                                    ? 'Reverso'
                                                    : 'Origen'}
                                            </strong>
                                            <small className="block text-slate-500">
                                                Asiento{' '}
                                                {entry.entry_number
                                                    ? `#${entry.entry_number}`
                                                    : 'sin numerar'}{' '}
                                                · {entry.entry_status}
                                            </small>
                                        </span>
                                    </span>
                                    <Link
                                        href={routes.show(company.public_id)}
                                        className="font-semibold text-[#7d0808]"
                                    >
                                        Abrir libros
                                    </Link>
                                </div>
                            ))}
                            {!sourceEntries.length && (
                                <p className="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-500">
                                    El asiento automático se creará al aprobar.
                                </p>
                            )}
                        </div>
                    </Card>
                </div>

                <aside className="space-y-5 lg:sticky lg:top-6 lg:self-start">
                    <div className="rounded-2xl border bg-white p-6 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-slate-500">
                                Estado
                            </span>
                            <span className="rounded-full bg-[#f5e7e7] px-3 py-1 text-xs font-semibold text-[#7d0808]">
                                {statusLabel}
                            </span>
                        </div>
                        <div className="mt-6 space-y-3 border-t pt-5">
                            <div className="flex justify-between">
                                <span>Total</span>
                                <strong>{money(document.total_cents)}</strong>
                            </div>
                            <div className="flex justify-between text-emerald-700">
                                <span>Pagado</span>
                                <strong>{money(paid)}</strong>
                            </div>
                            {payable &&
                                payable.credited_cents +
                                    payable.advances_cents +
                                    payable.adjusted_cents >
                                    0 && (
                                    <div className="flex justify-between text-sm text-slate-600">
                                        <span>NC, anticipos y ajustes</span>
                                        <strong>
                                            {money(
                                                payable.credited_cents +
                                                    payable.advances_cents +
                                                    payable.adjusted_cents,
                                            )}
                                        </strong>
                                    </div>
                                )}
                            {payable && payable.committed_cents > 0 && (
                                <div className="flex justify-between text-sm text-amber-700">
                                    <span>En proceso en Tesorería</span>
                                    <strong>
                                        {money(payable.committed_cents)}
                                    </strong>
                                </div>
                            )}
                            <div className="flex justify-between text-lg text-[#640000]">
                                <span>Pendiente</span>
                                <strong>{money(balance)}</strong>
                            </div>
                        </div>
                        <a
                            href={routes.purchasePdf.url([
                                company.public_id,
                                document.public_id,
                            ])}
                            className={`${secondary} mt-5 w-full`}
                        >
                            <Download className="size-4" /> Descargar
                            comprobante PDF
                        </a>
                    </div>
                    {document.status === 'draft' && isOwner && (
                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-6">
                            <FileCheck2 className="size-7 text-emerald-700" />
                            <h3 className="mt-3 font-semibold text-emerald-950">
                                Listo para aprobación
                            </h3>
                            <p className="mt-2 text-sm leading-6 text-emerald-800">
                                Verifica el documento y sus respaldos. Al
                                aprobar, Atlantia prepara el asiento contable
                                sin publicarlo todavía.
                            </p>
                            <button
                                type="button"
                                className={`${primary} mt-4 w-full`}
                                onClick={approve}
                            >
                                <CheckCircle2 className="size-4" /> Aprobar y
                                crear asiento
                            </button>
                        </div>
                    )}
                    {document.status === 'approved' &&
                        isOwner &&
                        paid === 0 &&
                        (payable === null ||
                            payable.credited_cents +
                                payable.advances_cents +
                                payable.adjusted_cents +
                                payable.committed_cents ===
                                0) && (
                            <button
                                type="button"
                                className={`${secondary} w-full`}
                                onClick={voidDocument}
                            >
                                <XCircle className="size-4" /> Anular documento
                            </button>
                        )}
                    <div className="rounded-2xl bg-[#f4e5e5] p-6 text-[#640000]">
                        <ShieldCheck className="size-7" />
                        <h3 className="mt-3 font-semibold">Flujo controlado</h3>
                        <p className="mt-2 text-sm leading-6">
                            Los documentos no se eliminan después de aprobarse.
                            Los pagos y asientos se anulan mediante reversos
                            auditables.
                        </p>
                    </div>
                </aside>
            </div>
        </AccountingSection>
    );
}
