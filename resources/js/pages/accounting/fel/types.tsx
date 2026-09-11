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
    status: string;
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
    pending: 'Pendiente',
    processing: 'Procesando',
    certified: 'Certificada (simulada)',
    failed: 'Fallida',
    cancelled: 'Anulada',
};
export const decimal = (cents: number) =>
    `${Math.trunc(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
export function TestNotice({ notice }: { notice: string }) {
    return (
        <div
            role="status"
            className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
            <strong>AMBIENTE DE PRUEBA · FEL SIMULADO</strong>
            <p className="mt-1">{notice}</p>
        </div>
    );
}
