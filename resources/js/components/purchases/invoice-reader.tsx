import { AlertTriangle, FileScan, Loader2, UserPlus } from 'lucide-react';
import { useRef, useState } from 'react';
import { csrfToken } from '@/lib/browser-security';

export type InvoiceLine = {
    description: string;
    quantity: string;
    unit_price: string;
    tax_treatment: string;
};

export type ReadInvoice = {
    source: 'xml' | 'pdf' | 'ocr';
    supplier: { tax_id: string | null; name: string | null; id: number | null };
    receiver_tax_id: string | null;
    fiscal_document_type: string | null;
    fiscal_series: string | null;
    fiscal_number: string | null;
    fiscal_authorization: string | null;
    document_date: string | null;
    currency: string;
    total: string | null;
    vat: string | null;
    lines: InvoiceLine[];
    review: string[];
    warnings: string[];
};

const SOURCE: Record<ReadInvoice['source'], string> = {
    xml: 'XML de la factura electrónica: datos exactos.',
    pdf: 'Texto del PDF: revisa los datos antes de guardar.',
    ocr: 'Lectura de imagen (OCR): revisa cada dato antes de guardar.',
};

/**
 * "Llenar desde la factura": sends the supplier's XML, PDF or photo to the server, which reads it and answers the
 * values for the purchase form. Nothing is saved until the accountant confirms the purchase.
 */
export function InvoiceReader({
    url,
    onRead,
    onRegisterSupplier,
}: {
    url: string;
    onRead: (invoice: ReadInvoice) => void;
    onRegisterSupplier: (supplier: {
        tax_id: string | null;
        name: string | null;
    }) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [reading, setReading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [invoice, setInvoice] = useState<ReadInvoice | null>(null);

    const read = async (file: File) => {
        setReading(true);
        setError(null);
        const body = new FormData();
        body.append('file', file);

        try {
            const response = await fetch(url, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const data = (await response.json()) as {
                invoice?: ReadInvoice;
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok || !data.invoice) {
                throw new Error(
                    (data.errors
                        ? Object.values(data.errors).flat()[0]
                        : data.message) ??
                        'No se pudo leer la factura. Inténtalo de nuevo.',
                );
            }

            setInvoice(data.invoice);
            onRead(data.invoice);
        } catch (exception) {
            setInvoice(null);
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'No se pudo leer la factura.',
            );
        } finally {
            setReading(false);

            if (input.current) {
                input.current.value = '';
            }
        }
    };

    return (
        <div className="rounded-xl border border-dashed border-[#c9a36a] bg-amber-50/60 p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="flex items-center gap-2 font-semibold text-[#142135]">
                        <FileScan className="size-4 text-[#8b1a1a]" />
                        Llenar desde la factura
                    </p>
                    <p className="text-xs text-slate-600">
                        Sube el XML, el PDF o una foto de la factura del
                        proveedor. Atlantia llena el formulario y tú lo revisas.
                    </p>
                </div>
                <button
                    type="button"
                    disabled={reading}
                    onClick={() => input.current?.click()}
                    className="inline-flex items-center gap-2 rounded-lg bg-[#8b1a1a] px-3 py-2 text-sm font-semibold text-white disabled:opacity-60"
                >
                    {reading ? (
                        <Loader2 className="size-4 animate-spin" />
                    ) : (
                        <FileScan className="size-4" />
                    )}
                    {reading ? 'Leyendo factura…' : 'Subir factura'}
                </button>
                <input
                    ref={input}
                    type="file"
                    accept=".xml,.pdf,.jpg,.jpeg,.png,image/*"
                    className="hidden"
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        if (file) {
                            void read(file);
                        }
                    }}
                />
            </div>
            {error && (
                <p className="mt-3 rounded-lg bg-red-50 p-2 text-sm text-red-700">
                    {error}
                </p>
            )}
            {invoice && (
                <div className="mt-3 space-y-2 text-sm">
                    <p className="text-slate-700">
                        <strong>{SOURCE[invoice.source]}</strong>{' '}
                        {invoice.supplier.name ?? 'Proveedor sin nombre'}
                        {invoice.supplier.tax_id
                            ? ` · NIT ${invoice.supplier.tax_id}`
                            : ''}
                        {invoice.total
                            ? ` · Total ${invoice.currency} ${invoice.total}`
                            : ''}
                    </p>
                    {invoice.warnings.map((warning) => (
                        <p
                            key={warning}
                            className="flex items-start gap-2 text-amber-800"
                        >
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            {warning}
                        </p>
                    ))}
                    {invoice.supplier.id === null &&
                        invoice.supplier.tax_id && (
                            <button
                                type="button"
                                onClick={() =>
                                    onRegisterSupplier(invoice.supplier)
                                }
                                className="inline-flex items-center gap-2 rounded-lg border border-[#8b1a1a] px-3 py-1.5 text-sm font-semibold text-[#8b1a1a]"
                            >
                                <UserPlus className="size-4" />
                                Registrar este proveedor
                            </button>
                        )}
                </div>
            )}
        </div>
    );
}
