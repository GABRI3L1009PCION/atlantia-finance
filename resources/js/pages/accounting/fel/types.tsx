export type Customer = {
    id: number;
    name: string;
    tax_id: string | null;
    email: string | null;
};
export type Line = {
    item: string;
    description: string;
    classification: string;
    quantity: string;
    price: string;
    discount: string;
};
export type StoredLine = Line & {
    id: number;
    unit_cents: number;
    subtotal_cents: number;
    discount_cents: number;
    tax_cents: number;
    total_cents: number;
};
export type Document = {
    public_id: string;
    number: number;
    series: string;
    customer_id: number;
    issued_on: string;
    currency: string;
    document_type: string;
    sales_invoice_id: number | null;
    status: string;
    provider: string;
    awaiting_sat_at: string | null;
    sat_source: string | null;
    certified_at: string | null;
    subtotal_cents: number;
    discount_cents: number;
    tax_cents: number;
    total_cents: number;
    observations: string | null;
    receiver_snapshot: {
        name: string;
        nit: string;
        address: string;
        email: string | null;
    };
    fiscal_snapshot: { name: string; nit: string };
    authorization_number: string | null;
    fel_series: string | null;
    fel_number: string | null;
    pdf_status: string;
    email_status: string;
    email_sent_at: string | null;
    error_code: string | null;
    lines: StoredLine[];
};
export const labels: Record<string, string> = {
    draft: 'Borrador',
    awaiting_sat: 'Pendiente de emitir en SAT',
    pending: 'Pendiente',
    processing: 'Procesando',
    certified: 'Certificada (simulada)',
    failed: 'Fallida',
    cancelled: 'Anulada',
};
/** "Certificada (simulada)" only makes sense for the test provider; a document registered from the SAT portal is simply issued. */
export const statusLabel = (status: string, provider?: string): string =>
    status === 'certified' && provider === 'sat_manual'
        ? 'Emitida en SAT'
        : (labels[status] ?? status);
export const decimal = (cents: number) =>
    `${Math.trunc(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
export function TestNotice({
    notice,
    compact = false,
}: {
    notice: string | null;
    compact?: boolean;
}) {
    if (!notice) {
        return null;
    }

    return (
        <div
            role="status"
            className={`rounded-xl border border-amber-300 bg-amber-50 text-amber-900 ${
                compact ? 'px-4 py-2 text-xs' : 'p-4 text-sm'
            }`}
        >
            <strong>AMBIENTE DE PRUEBA · FEL SIMULADO</strong>
            <p className={compact ? 'mt-0.5' : 'mt-1'}>{notice}</p>
        </div>
    );
}
