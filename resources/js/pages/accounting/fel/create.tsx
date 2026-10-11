import { Link, useForm, useHttp } from '@inertiajs/react';
import { useState } from 'react';
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
import * as fel from '@/routes/accounting/fel';
import { decimal, TestNotice } from './types';
import type { Customer, Document, Line } from './types';

type Quote = {
    subtotal_cents: number;
    discount_cents: number;
    tax_cents: number;
    total_cents: number;
    lines: { tax_cents: number; total_cents: number }[];
};
const blank: Line = {
    item: '',
    description: '',
    classification: 'service',
    quantity: '1',
    price: '0.00',
    discount: '0.00',
};

type FelCreateProps = {
    company: Company;
    customers: Customer[];
    invoices?: {
        id: number;
        number: number;
        sales_customer_id: number;
        total_cents: number;
        issued_on: string;
    }[];
    document: Document | null;
    today: string;
    notice: string;
};

export function FelInvoiceForm({
    company,
    customers,
    invoices = [],
    document,
    today,
}: FelCreateProps) {
    const form = useForm({
        issued_on: document?.issued_on.slice(0, 10) ?? today,
        currency: 'GTQ',
        document_type: document?.document_type ?? 'FACT',
        sales_invoice_id: document?.sales_invoice_id
            ? String(document.sales_invoice_id)
            : '',
        intent: 'draft',
        customer_id: document ? String(document.customer_id) : '',
        receiver_name: document?.receiver_snapshot.name ?? '',
        receiver_nit: document?.receiver_snapshot.nit ?? '',
        receiver_address: document?.receiver_snapshot.address ?? '',
        receiver_email: document?.receiver_snapshot.email ?? '',
        observations: document?.observations ?? '',
        lines: document?.lines.map((l) => ({
            item: l.item,
            description: l.description,
            classification: l.classification,
            quantity: l.quantity,
            price: decimal(l.unit_cents),
            discount: decimal(l.discount_cents),
        })) ?? [{ ...blank }],
    });
    const quote = useHttp<typeof form.data, Quote>(form.data);
    const [quotedInput, setQuotedInput] = useState('');
    const validQuote = quotedInput === JSON.stringify(form.data);
    function changeLine(index: number, key: keyof Line, value: string) {
        form.setData(
            'lines',
            form.data.lines.map((line, i) =>
                i === index ? { ...line, [key]: value } : line,
            ),
        );
    }
    function save(intent: string) {
        form.transform((data) => ({ ...data, intent }));

        if (document) {
            form.patch(fel.update.url([company.public_id, document.public_id]));
        } else {
            form.post(fel.store.url(company.public_id));
        }
    }
    async function calculate() {
        const input = JSON.stringify(form.data);
        quote.transform(() => form.data);

        try {
            await quote.post(fel.quote.url(company.public_id));
            setQuotedInput(input);
        } catch {
            setQuotedInput('');
        }
    }

    return (
        <>
            <Errors errors={form.errors} />
            <Errors errors={quote.errors} />
            <div className="grid items-start gap-5 xl:grid-cols-[1fr_300px]">
                <div className="space-y-5">
                    <Card title="Datos del documento">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label>
                                Fecha
                                <input
                                    type="date"
                                    className={field}
                                    value={form.data.issued_on}
                                    onChange={(e) =>
                                        form.setData(
                                            'issued_on',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Tipo de documento
                                <select
                                    className={field}
                                    value={form.data.document_type}
                                    onChange={(e) =>
                                        form.setData(
                                            'document_type',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="FACT">Factura (FACT)</option>
                                    <option value="NCRE">
                                        Nota de crédito (NCRE)
                                    </option>
                                </select>
                            </label>
                            {form.data.document_type === 'NCRE' && (
                                <label className="sm:col-span-2">
                                    Factura interna que acredita
                                    <select
                                        className={field}
                                        value={form.data.sales_invoice_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'sales_invoice_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            Seleccionar factura del cliente
                                        </option>
                                        {invoices
                                            .filter(
                                                (invoice) =>
                                                    String(
                                                        invoice.sales_customer_id,
                                                    ) === form.data.customer_id,
                                            )
                                            .map((invoice) => (
                                                <option
                                                    key={invoice.id}
                                                    value={invoice.id}
                                                >
                                                    #{invoice.number} ·{' '}
                                                    {invoice.issued_on} · Q{' '}
                                                    {decimal(
                                                        invoice.total_cents,
                                                    )}
                                                </option>
                                            ))}
                                    </select>
                                    <span className="text-xs text-slate-500">
                                        Al certificarse, reduce el saldo por
                                        cobrar de esa factura y se contabiliza
                                        contra ella; si se anula, el saldo
                                        vuelve.
                                    </span>
                                </label>
                            )}
                            <label>
                                Moneda
                                <input className={field} value="GTQ" readOnly />
                            </label>
                            <label className="sm:col-span-2">
                                Cliente
                                <select
                                    className={field}
                                    value={form.data.customer_id}
                                    onChange={(e) => {
                                        const c = customers.find(
                                            (c) =>
                                                String(c.id) === e.target.value,
                                        );
                                        form.setData((data) => ({
                                            ...data,
                                            customer_id: e.target.value,
                                            receiver_name: c?.name ?? '',
                                            receiver_nit: c?.tax_id ?? '',
                                            receiver_email: c?.email ?? '',
                                        }));
                                    }}
                                >
                                    <option value="">
                                        Seleccionar cliente
                                    </option>
                                    {customers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                NIT
                                <input
                                    className={field}
                                    value={form.data.receiver_nit}
                                    onChange={(e) =>
                                        form.setData(
                                            'receiver_nit',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Nombre / razón social
                                <input
                                    className={field}
                                    value={form.data.receiver_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'receiver_name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Dirección
                                <input
                                    className={field}
                                    value={form.data.receiver_address}
                                    onChange={(e) =>
                                        form.setData(
                                            'receiver_address',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label>
                                Correo
                                <input
                                    type="email"
                                    className={field}
                                    value={form.data.receiver_email}
                                    onChange={(e) =>
                                        form.setData(
                                            'receiver_email',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                        {!customers.length && (
                            <p className="mt-4 text-sm text-amber-800">
                                Registra un cliente en la sección Clientes para
                                continuar.
                            </p>
                        )}
                    </Card>
                    <Card title="Detalle de la factura">
                        <p className="mb-4 text-sm text-slate-500">
                            Precios sin IVA. Los impuestos se calculan con la
                            configuración fiscal vigente para la fecha del
                            documento.
                        </p>
                        <div className="space-y-4">
                            {form.data.lines.map((line, index) => (
                                <div
                                    key={index}
                                    className="rounded-xl border border-slate-200 p-4"
                                >
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <label>
                                            Producto / servicio
                                            <input
                                                className={field}
                                                value={line.item}
                                                onChange={(e) =>
                                                    changeLine(
                                                        index,
                                                        'item',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label>
                                            Clasificación
                                            <select
                                                className={field}
                                                value={line.classification}
                                                onChange={(e) =>
                                                    changeLine(
                                                        index,
                                                        'classification',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="service">
                                                    Servicio
                                                </option>
                                                <option value="goods">
                                                    Bien
                                                </option>
                                            </select>
                                        </label>
                                        <label className="sm:col-span-2">
                                            Descripción
                                            <input
                                                className={field}
                                                value={line.description}
                                                onChange={(e) =>
                                                    changeLine(
                                                        index,
                                                        'description',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        {(
                                            [
                                                'quantity',
                                                'price',
                                                'discount',
                                            ] as const
                                        ).map((key) => (
                                            <label key={key}>
                                                {
                                                    {
                                                        quantity: 'Cantidad',
                                                        price: 'Precio unitario',
                                                        discount:
                                                            'Descuento de línea',
                                                    }[key]
                                                }
                                                <input
                                                    inputMode="decimal"
                                                    className={field}
                                                    value={line[key]}
                                                    onChange={(e) =>
                                                        changeLine(
                                                            index,
                                                            key,
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                        ))}
                                    </div>
                                    {validQuote && quote.response && (
                                        <p className="mt-3 text-sm">
                                            Impuesto:{' '}
                                            {money(
                                                quote.response.lines[index]
                                                    .tax_cents,
                                            )}{' '}
                                            · Total:{' '}
                                            {money(
                                                quote.response.lines[index]
                                                    .total_cents,
                                            )}
                                        </p>
                                    )}
                                    {form.data.lines.length > 1 && (
                                        <button
                                            type="button"
                                            className="mt-3 text-sm text-red-700"
                                            onClick={() =>
                                                form.setData(
                                                    'lines',
                                                    form.data.lines.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            Quitar línea
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <button
                            className={secondary + ' mt-4'}
                            disabled={form.data.lines.length >= 100}
                            onClick={() =>
                                form.setData('lines', [
                                    ...form.data.lines,
                                    { ...blank },
                                ])
                            }
                        >
                            Añadir línea
                        </button>
                    </Card>
                    <Card title="Observaciones">
                        <textarea
                            className={field}
                            value={form.data.observations}
                            onChange={(e) =>
                                form.setData('observations', e.target.value)
                            }
                        />
                    </Card>
                </div>
                <div className="space-y-4 xl:sticky xl:top-4">
                    <Card title="Resumen">
                        <button
                            type="button"
                            className={secondary}
                            disabled={quote.processing}
                            onClick={calculate}
                        >
                            {quote.processing
                                ? 'Calculando…'
                                : 'Calcular impuestos y total'}
                        </button>
                        {validQuote && quote.response ? (
                            <dl className="mt-5 space-y-3">
                                {Object.entries({
                                    subtotal_cents: 'Subtotal',
                                    discount_cents: 'Descuentos',
                                    tax_cents: 'IVA',
                                    total_cents: 'Total',
                                }).map(([key, label]) => (
                                    <div
                                        key={key}
                                        className="flex justify-between gap-3"
                                    >
                                        <dt>{label}</dt>
                                        <dd className="font-semibold">
                                            {money(
                                                quote.response![
                                                    key as keyof Omit<
                                                        Quote,
                                                        'lines'
                                                    >
                                                ],
                                            )}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        ) : (
                            <p className="mt-4 text-sm text-slate-500">
                                Calcula el total para revisar los importes antes
                                de emitir.
                            </p>
                        )}
                        <p className="mt-4 text-xs text-slate-500">
                            Otros impuestos: no aplicados en esta fase.
                        </p>
                        <div className="mt-6 flex flex-col gap-3">
                            <button
                                className={secondary}
                                disabled={form.processing}
                                onClick={() => save('draft')}
                            >
                                Guardar borrador
                            </button>
                            <button
                                className={primary}
                                disabled={form.processing || !validQuote}
                                onClick={() => save('issue')}
                            >
                                Emitir factura de prueba
                            </button>
                        </div>
                        <p className="mt-4 text-xs text-amber-800">
                            Esta factura se procesará mediante el certificador
                            FEL de prueba.
                        </p>
                    </Card>
                </div>
            </div>
        </>
    );
}

export default function FelCreate(props: FelCreateProps) {
    return (
        <AccountingSection
            company={props.company}
            title={props.document ? 'Editar borrador' : 'Nueva factura'}
            action={
                <Link
                    href={fel.index(props.company.public_id)}
                    className={secondary}
                >
                    Documentos
                </Link>
            }
        >
            <TestNotice notice={props.notice} />
            <FelInvoiceForm {...props} />
        </AccountingSection>
    );
}
