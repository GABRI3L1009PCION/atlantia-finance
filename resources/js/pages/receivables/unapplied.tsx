import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    primaryButton,
} from '@/components/office-admin/kit';
import { amount } from '@/components/receivables/kit';
import type {
    Receivable,
    ReceivablesContext,
    ReceivablesPermissions,
    SharedReceipt,
} from '@/components/receivables/kit';
import { Card, Table } from '@/components/supervisor/kit';

type Deposit = {
    id: number;
    company: string;
    date: string;
    reference: string;
    description: string;
    amount_cents: number;
    account: string;
    status_label: string;
};

function ApplyForm({
    receipt,
    documents,
}: {
    receipt: SharedReceipt;
    documents: Receivable[];
}) {
    const [values, setValues] = useState<Record<number, string>>({});
    const candidates = documents.filter(
        (document) =>
            document.company_id === receipt.company_id &&
            (!receipt.identified || document.customer === receipt.customer),
    );
    const total = Object.values(values).reduce(
        (sum, value) => sum + (Number(value) || 0),
        0,
    );

    if (candidates.length === 0) {
        return (
            <p className="text-xs text-slate-500">
                No hay documentos abiertos compatibles en la empresa.
            </p>
        );
    }

    return (
        <div className="grid gap-2">
            <Table
                headers={[
                    'Documento',
                    'Cliente',
                    'Vence',
                    'Saldo',
                    'Aplicar (Q)',
                ]}
            >
                {candidates.map((document) => (
                    <tr key={document.id}>
                        <td className="px-2 py-1 font-bold">
                            {document.document}
                        </td>
                        <td className="px-2 py-1">{document.customer}</td>
                        <td className="px-2 py-1">
                            {formatDate(document.due_date)}
                        </td>
                        <td className="px-2 py-1">
                            {amount(document.outstanding_cents)}
                        </td>
                        <td className="px-2 py-1">
                            <input
                                aria-label={`Aplicar a ${document.document}`}
                                className="h-8 w-28 rounded-md border border-slate-300 px-2 text-xs"
                                inputMode="decimal"
                                value={values[document.id] ?? ''}
                                onChange={(event) =>
                                    setValues({
                                        ...values,
                                        [document.id]: event.target.value,
                                    })
                                }
                            />
                        </td>
                    </tr>
                ))}
            </Table>
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs text-[#526b92]">
                    Aplicando Q {total.toFixed(2)} de{' '}
                    {amount(receipt.unapplied_cents)} disponibles. Un cobro se
                    aplica a documentos de un solo cliente; el excedente queda
                    visible sin aplicar.
                </p>
                <button
                    type="button"
                    className={primaryButton}
                    disabled={total <= 0}
                    onClick={() =>
                        router.post(
                            `/receivables/receipts/${receipt.id}/apply`,
                            {
                                allocations: Object.entries(values)
                                    .filter(([, value]) => Number(value) > 0)
                                    .map(([id, value]) => ({
                                        sales_invoice_id: Number(id),
                                        amount: value,
                                    })),
                            },
                            {
                                preserveScroll: true,
                                onSuccess: () => setValues({}),
                            },
                        )
                    }
                >
                    Aplicar cobro
                </button>
            </div>
        </div>
    );
}

export default function ReceivablesUnapplied({
    receipts,
    deposits,
    openDocuments,
    focus,
    permissions,
    notice,
    errors,
}: {
    context: ReceivablesContext;
    receipts: SharedReceipt[];
    deposits: Deposit[];
    openDocuments: Receivable[];
    focus: number | null;
    permissions: ReceivablesPermissions;
    notice: string | null;
    errors: Record<string, string>;
}) {
    const [selected, setSelected] = useState<number | null>(focus);

    return (
        <>
            <Head title="Cobros sin aplicar" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Cobros sin aplicar"
                    subtitle="Dinero recibido por Tesorería que aún no se aplica a documentos; nunca se altera una factura para absorber un excedente"
                />
                {errors.allocations && (
                    <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {errors.allocations}
                    </p>
                )}
                <Card title={`${receipts.length} cobro(s) sin aplicar`}>
                    <Table
                        headers={[
                            'Fecha',
                            'Empresa',
                            'Cliente',
                            'Cuenta',
                            'Referencia',
                            'Monto',
                            'Aplicado',
                            'Sin aplicar',
                            '',
                        ]}
                        empty={
                            receipts.length === 0 &&
                            'No existen cobros pendientes de aplicación.'
                        }
                    >
                        {receipts.map((receipt) => (
                            <Fragment key={receipt.id}>
                                <tr>
                                    <td className="px-2 py-2">
                                        {formatDate(receipt.received_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {receipt.company}
                                    </td>
                                    <td className="px-2 py-2 font-bold">
                                        {receipt.customer}
                                    </td>
                                    <td className="px-2 py-2">
                                        {receipt.account}
                                    </td>
                                    <td className="px-2 py-2">
                                        {receipt.reference}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(receipt.amount_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {amount(receipt.applied_cents)}
                                    </td>
                                    <td className="px-2 py-2 font-semibold text-amber-700">
                                        {amount(receipt.unapplied_cents)}
                                    </td>
                                    <td className="px-2 py-2">
                                        {permissions.canApply && (
                                            <button
                                                type="button"
                                                className="rounded-md bg-blue-600 px-3 py-1 text-xs font-bold text-white"
                                                onClick={() =>
                                                    setSelected(
                                                        selected === receipt.id
                                                            ? null
                                                            : receipt.id,
                                                    )
                                                }
                                            >
                                                {selected === receipt.id
                                                    ? 'Cerrar'
                                                    : 'Aplicar'}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                                {selected === receipt.id && (
                                    <tr key={`apply-${receipt.id}`}>
                                        <td
                                            colSpan={9}
                                            className="bg-slate-50 px-2 py-3"
                                        >
                                            <ApplyForm
                                                receipt={receipt}
                                                documents={openDocuments}
                                            />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </Table>
                </Card>
                <Card title="Depósitos sin identificar en Tesorería">
                    <Table
                        headers={[
                            'Fecha',
                            'Empresa',
                            'Cuenta',
                            'Referencia',
                            'Descripción',
                            'Monto',
                            'Estado',
                        ]}
                        empty={
                            deposits.length === 0 &&
                            'No hay depósitos pendientes de identificar.'
                        }
                    >
                        {deposits.map((deposit) => (
                            <tr key={deposit.id}>
                                <td className="px-2 py-2">
                                    {formatDate(deposit.date)}
                                </td>
                                <td className="px-2 py-2">{deposit.company}</td>
                                <td className="px-2 py-2">{deposit.account}</td>
                                <td className="px-2 py-2 font-bold">
                                    {deposit.reference}
                                </td>
                                <td className="px-2 py-2">
                                    {deposit.description}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(deposit.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {deposit.status_label}
                                </td>
                            </tr>
                        ))}
                    </Table>
                    <p className="mt-2 text-[11px] text-[#526b92]">
                        Si identificas al cliente, avisa a Tesorería para que
                        registre el cobro; CxC no inventa clientes ni crea
                        cobros duplicados.
                    </p>
                </Card>
            </Shell>
        </>
    );
}
