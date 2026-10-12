import { Head, router } from '@inertiajs/react';
import { FileSpreadsheet, FileText, Printer, Send } from 'lucide-react';
import { useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    secondaryButton,
} from '@/components/office-admin/kit';
import { amount, useReceivablesHref } from '@/components/receivables/kit';
import type {
    Receivable,
    ReceivablesContext,
    ReceivablesPermissions,
} from '@/components/receivables/kit';
import { DocumentsTable } from '@/components/receivables/tables';
import { Card, Table } from '@/components/supervisor/kit';

type Statement = {
    company: { name: string; tax_id: string };
    customer: {
        id: number;
        name: string;
        tax_id: string | null;
        email: string | null;
    };
    from: string;
    to: string;
    opening_cents: number;
    movements: {
        date: string;
        type: string;
        document: string;
        reference: string | null;
        charge_cents: number;
        credit_cents: number;
        balance_cents: number;
    }[];
    charges_cents: number;
    credits_cents: number;
    closing_cents: number;
    open_documents: Receivable[];
};

export default function ReceivablesStatements({
    customers,
    selection,
    statement,
    templates,
    permissions,
    notice,
    errors,
}: {
    context: ReceivablesContext;
    customers: { id: number; name: string; company: string }[];
    selection: { customer_id: number | null; from: string; to: string };
    statement: Statement | null;
    templates: { id: number; name: string }[];
    permissions: ReceivablesPermissions;
    notice: string | null;
    errors: Record<string, string>;
}) {
    const href = useReceivablesHref();
    const [form, setForm] = useState({
        customer_id: selection.customer_id ? String(selection.customer_id) : '',
        from: selection.from,
        to: selection.to,
    });
    const query = `customer_id=${selection.customer_id}&from=${selection.from}&to=${selection.to}`;

    return (
        <>
            <Head title="Estados de cuenta" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Estados de cuenta"
                    subtitle="La pantalla, el PDF, el Excel y el correo usan exactamente el mismo cálculo"
                />
                <Card title="Cliente y rango">
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(href('/receivables/statements'), form);
                        }}
                    >
                        <label className="grid min-w-64 gap-1 text-xs font-semibold">
                            Cliente
                            <select
                                className={input}
                                value={form.customer_id}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        customer_id: event.target.value,
                                    })
                                }
                            >
                                <option value="">Elegir…</option>
                                {customers.map((customer) => (
                                    <option
                                        key={customer.id}
                                        value={customer.id}
                                    >
                                        {customer.company} · {customer.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold">
                            Desde
                            <input
                                type="date"
                                className={input}
                                value={form.from}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        from: event.target.value,
                                    })
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-xs font-semibold">
                            Hasta
                            <input
                                type="date"
                                className={input}
                                value={form.to}
                                onChange={(event) =>
                                    setForm({ ...form, to: event.target.value })
                                }
                            />
                        </label>
                        <button
                            type="submit"
                            className={secondaryButton}
                            disabled={form.customer_id === ''}
                        >
                            Generar
                        </button>
                    </form>
                </Card>

                {statement && (
                    <>
                        <Card
                            title={`${statement.customer.name} · del ${formatDate(statement.from)} al ${formatDate(statement.to)}`}
                            action={
                                <div className="flex flex-wrap gap-1">
                                    <a
                                        href={`/receivables/statements/export?${query}&format=pdf`}
                                        className={secondaryButton}
                                    >
                                        <FileText className="size-3.5 text-red-700" />{' '}
                                        PDF
                                    </a>
                                    <a
                                        href={`/receivables/statements/export?${query}&format=xlsx`}
                                        className={secondaryButton}
                                    >
                                        <FileSpreadsheet className="size-3.5 text-emerald-700" />{' '}
                                        Excel
                                    </a>
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => window.print()}
                                    >
                                        <Printer className="size-3.5" />{' '}
                                        Imprimir
                                    </button>
                                    {permissions.canSend && templates[0] && (
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            disabled={!statement.customer.email}
                                            title={
                                                statement.customer.email ??
                                                'El cliente no tiene correo en Ventas'
                                            }
                                            onClick={() =>
                                                router.post(
                                                    '/receivables/statements/send',
                                                    {
                                                        customer_id:
                                                            statement.customer
                                                                .id,
                                                        from: statement.from,
                                                        to: statement.to,
                                                        template_id:
                                                            templates[0].id,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Send className="size-3.5" /> Enviar
                                            al cliente
                                        </button>
                                    )}
                                </div>
                            }
                        >
                            {errors.customer_id && (
                                <p className="mb-2 text-xs text-red-700">
                                    {errors.customer_id}
                                </p>
                            )}
                            <p className="mb-2 text-xs text-[#526b92]">
                                {statement.company.name} · NIT{' '}
                                {statement.company.tax_id} · Cliente NIT{' '}
                                {statement.customer.tax_id ?? 'No indicado'}
                            </p>
                            <Table
                                headers={[
                                    'Fecha',
                                    'Movimiento',
                                    'Documento',
                                    'Referencia',
                                    'Cargos',
                                    'Abonos',
                                    'Saldo',
                                ]}
                            >
                                <tr className="font-semibold">
                                    <td className="px-2 py-2">
                                        {formatDate(statement.from)}
                                    </td>
                                    <td className="px-2 py-2" colSpan={5}>
                                        Saldo inicial
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(statement.opening_cents)}
                                    </td>
                                </tr>
                                {statement.movements.map((movement, index) => (
                                    <tr key={index}>
                                        <td className="px-2 py-2">
                                            {formatDate(movement.date)}
                                        </td>
                                        <td className="px-2 py-2">
                                            {movement.type}
                                        </td>
                                        <td className="px-2 py-2">
                                            {movement.document}
                                        </td>
                                        <td className="px-2 py-2">
                                            {movement.reference ?? '—'}
                                        </td>
                                        <td className="px-2 py-2">
                                            {movement.charge_cents
                                                ? amount(movement.charge_cents)
                                                : ''}
                                        </td>
                                        <td className="px-2 py-2">
                                            {movement.credit_cents
                                                ? amount(movement.credit_cents)
                                                : ''}
                                        </td>
                                        <td className="px-2 py-2">
                                            {amount(movement.balance_cents)}
                                        </td>
                                    </tr>
                                ))}
                                <tr className="font-black">
                                    <td className="px-2 py-2">
                                        {formatDate(statement.to)}
                                    </td>
                                    <td className="px-2 py-2" colSpan={3}>
                                        Saldo final
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(statement.charges_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(statement.credits_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(statement.closing_cents)}
                                    </td>
                                </tr>
                            </Table>
                        </Card>
                        <Card title="Documentos abiertos a la fecha">
                            <DocumentsTable
                                documents={statement.open_documents}
                                empty="El cliente no tiene documentos abiertos."
                                showCustomer={false}
                            />
                        </Card>
                    </>
                )}
            </Shell>
        </>
    );
}
