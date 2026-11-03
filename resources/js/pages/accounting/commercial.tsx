import { router, useForm } from '@inertiajs/react';
import { FileSignature, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    Badge,
    cell,
    Empty,
    head,
    Label,
    PageShell,
} from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
    secondary,
    shortDate,
} from '@/components/accounting-ui';

type Line = {
    description: string;
    quantity: number;
    unit_price: string;
    tax_treatment: string;
    account_id: string;
};

type Document = {
    public_id: string;
    kind: string;
    code: string;
    counterparty: string;
    issued_on: string;
    valid_until: string | null;
    status: string;
    total_cents: number;
    lines: Line[];
    converted_type: string | null;
    converted_public_id: string | null;
};

type Props = {
    company: Company;
    documents: Document[];
    kinds: Record<string, string>;
    statuses: Record<string, string>;
    customers: { id: number; name: string }[];
    suppliers: { id: number; name: string }[];
    accounts: { id: number; code: string; name: string; type: string }[];
    credits: {
        id: number;
        supplier: string;
        date: string;
        reference: string;
        amount_cents: number;
        available_cents: number;
        status: string;
    }[];
    can: { sales: boolean; purchases: boolean };
};

type Tab = 'quote' | 'sales_order' | 'purchase_order' | 'credits';

const statusTone: Record<string, 'slate' | 'green' | 'amber' | 'red'> = {
    draft: 'slate',
    sent: 'amber',
    accepted: 'green',
    converted: 'green',
    rejected: 'red',
    cancelled: 'red',
};

const today = () => new Date().toISOString().slice(0, 10);

const emptyLine = (): Line => ({
    description: '',
    quantity: 1,
    unit_price: '',
    tax_treatment: 'goods',
    account_id: '',
});

function DocumentRow({
    document,
    base,
    company,
    statuses,
    canManage,
}: {
    document: Document;
    base: string;
    company: string;
    statuses: Record<string, string>;
    canManage: boolean;
}) {
    const convert = useForm({
        target:
            document.kind === 'purchase_order'
                ? 'purchase_document'
                : 'sales_invoice',
        document_date: today(),
        due_on: '',
        fiscal_series: '',
        fiscal_number: '',
        fiscal_authorization: '',
    });
    const [open, setOpen] = useState(false);
    const transition = (status: string) =>
        router.patch(
            `${base}/${document.public_id}`,
            { status },
            { preserveScroll: true },
        );
    const live = !['converted', 'cancelled', 'rejected'].includes(
        document.status,
    );
    const convertedHref =
        document.converted_public_id === null
            ? null
            : document.converted_type === 'sales_invoice'
              ? `/accounting/companies/${company}/sales/invoices/${document.converted_public_id}`
              : document.converted_type === 'purchase_document'
                ? `/accounting/companies/${company}/purchases/documents/${document.converted_public_id}`
                : null;

    return (
        <>
            <tr className="border-t border-slate-100 align-top">
                <td className={cell}>
                    <span className="font-semibold">{document.code}</span>
                    <span className="block text-xs text-slate-500">
                        {document.lines.length} línea(s)
                    </span>
                </td>
                <td className={cell}>{document.counterparty}</td>
                <td className={cell}>
                    {shortDate(document.issued_on)}
                    {document.valid_until && (
                        <span className="block text-xs text-slate-500">
                            Vigente al {shortDate(document.valid_until)}
                        </span>
                    )}
                </td>
                <td className={`${cell} text-right tabular-nums`}>
                    {money(document.total_cents)}
                </td>
                <td className={cell}>
                    <Badge tone={statusTone[document.status] ?? 'slate'}>
                        {statuses[document.status] ?? document.status}
                    </Badge>
                    {convertedHref && (
                        <a
                            href={convertedHref}
                            className="mt-1 block text-xs font-semibold text-[#640000] underline"
                        >
                            Ver documento creado
                        </a>
                    )}
                </td>
                <td className={`${cell} text-right`}>
                    {canManage && live && (
                        <div className="flex flex-wrap justify-end gap-2">
                            {document.status === 'draft' && (
                                <button
                                    type="button"
                                    className={secondary}
                                    onClick={() => transition('sent')}
                                >
                                    Marcar enviada
                                </button>
                            )}
                            {document.status === 'sent' &&
                                document.kind === 'quote' && (
                                    <>
                                        <button
                                            type="button"
                                            className={secondary}
                                            onClick={() =>
                                                transition('accepted')
                                            }
                                        >
                                            Aceptada
                                        </button>
                                        <button
                                            type="button"
                                            className={secondary}
                                            onClick={() =>
                                                transition('rejected')
                                            }
                                        >
                                            Rechazada
                                        </button>
                                    </>
                                )}
                            <button
                                type="button"
                                className={primary}
                                onClick={() => setOpen(!open)}
                            >
                                Convertir
                            </button>
                            <button
                                type="button"
                                className={secondary}
                                onClick={() =>
                                    window.confirm(
                                        `¿Anular ${document.code}?`,
                                    ) && transition('cancelled')
                                }
                            >
                                Anular
                            </button>
                        </div>
                    )}
                </td>
            </tr>
            {open && (
                <tr>
                    <td colSpan={6} className="bg-slate-50 p-4">
                        <form
                            className="grid gap-3 md:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                convert.post(
                                    `${base}/${document.public_id}/convert`,
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <Label text="Convertir en">
                                <select
                                    className={field}
                                    value={convert.data.target}
                                    onChange={(event) =>
                                        convert.setData(
                                            'target',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {document.kind === 'quote' && (
                                        <option value="sales_order">
                                            Orden de venta
                                        </option>
                                    )}
                                    {document.kind !== 'purchase_order' && (
                                        <option value="sales_invoice">
                                            Factura (borrador)
                                        </option>
                                    )}
                                    {document.kind === 'purchase_order' && (
                                        <option value="purchase_document">
                                            Compra (borrador)
                                        </option>
                                    )}
                                </select>
                            </Label>
                            <Label text="Fecha">
                                <input
                                    type="date"
                                    className={field}
                                    value={convert.data.document_date}
                                    onChange={(event) =>
                                        convert.setData(
                                            'document_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            {convert.data.target !== 'sales_order' && (
                                <Label text="Vence">
                                    <input
                                        type="date"
                                        className={field}
                                        value={convert.data.due_on}
                                        onChange={(event) =>
                                            convert.setData(
                                                'due_on',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Label>
                            )}
                            {convert.data.target === 'purchase_document' && (
                                <>
                                    <Label text="Serie de la factura">
                                        <input
                                            className={field}
                                            value={convert.data.fiscal_series}
                                            onChange={(event) =>
                                                convert.setData(
                                                    'fiscal_series',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                    <Label text="Número de la factura">
                                        <input
                                            className={field}
                                            value={convert.data.fiscal_number}
                                            onChange={(event) =>
                                                convert.setData(
                                                    'fiscal_number',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                    <Label text="Autorización FEL">
                                        <input
                                            className={field}
                                            value={
                                                convert.data
                                                    .fiscal_authorization
                                            }
                                            onChange={(event) =>
                                                convert.setData(
                                                    'fiscal_authorization',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                </>
                            )}
                            <div className="flex items-end">
                                <button
                                    className={primary}
                                    disabled={convert.processing}
                                >
                                    Convertir
                                </button>
                            </div>
                        </form>
                        <Errors
                            errors={convert.errors as Record<string, string>}
                        />
                    </td>
                </tr>
            )}
        </>
    );
}

export default function Commercial({
    company,
    documents,
    kinds,
    statuses,
    customers,
    suppliers,
    accounts,
    credits,
    can,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/commercial`;
    const [tab, setTab] = useState<Tab>(can.sales ? 'quote' : 'purchase_order');
    const isPurchase = tab === 'purchase_order';
    const form = useForm({
        kind: 'quote',
        counterparty_id: '',
        issued_on: today(),
        valid_until: '',
        notes: '',
        lines: [emptyLine()],
    });
    const credit = useForm({
        supplier_id: '',
        amount: '',
        issued_on: today(),
        reason: '',
        account_id: '',
        with_vat: true,
        fiscal_series: '',
        fiscal_number: '',
    });
    const setLine = (index: number, values: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((line, position) =>
                position === index ? { ...line, ...values } : line,
            ),
        );
    const rows = documents.filter((document) => document.kind === tab);
    const canManage = isPurchase ? can.purchases : can.sales;
    const tabs: { key: Tab; label: string }[] = [
        { key: 'quote', label: 'Cotizaciones' },
        { key: 'sales_order', label: 'Órdenes de venta' },
        { key: 'purchase_order', label: 'Órdenes de compra' },
        { key: 'credits', label: 'Saldos a favor con proveedores' },
    ];

    return (
        <PageShell
            company={company}
            title="Cotizaciones y órdenes"
            subtitle="Cotizaciones y órdenes que se convierten en factura o compra, y créditos de proveedores"
            icon={FileSignature}
        >
            <div className="flex flex-wrap gap-2">
                {tabs.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        className={tab === item.key ? primary : secondary}
                        onClick={() => setTab(item.key)}
                    >
                        {item.label}
                    </button>
                ))}
            </div>

            {tab === 'credits' ? (
                <Card title="Saldos a favor con proveedores">
                    <p className="mb-3 text-sm text-slate-600">
                        Cuando un proveedor da una nota de crédito que no sale
                        de una factura pendiente (o acredita más de lo que se le
                        debe), regístrala aquí: queda como saldo a favor y se
                        aplica a sus próximas facturas desde Anticipos.
                    </p>
                    {credits.length === 0 ? (
                        <Empty>Aún no hay saldos a favor.</Empty>
                    ) : (
                        <table className="mb-4 w-full text-left">
                            <thead>
                                <tr>
                                    <th className={head}>Proveedor</th>
                                    <th className={head}>Fecha</th>
                                    <th className={head}>Referencia</th>
                                    <th className={`${head} text-right`}>
                                        Monto
                                    </th>
                                    <th className={`${head} text-right`}>
                                        Disponible
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {credits.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            {item.supplier}
                                        </td>
                                        <td className={cell}>
                                            {shortDate(item.date)}
                                        </td>
                                        <td className={cell}>
                                            {item.reference}
                                        </td>
                                        <td
                                            className={`${cell} text-right tabular-nums`}
                                        >
                                            {money(item.amount_cents)}
                                        </td>
                                        <td
                                            className={`${cell} text-right tabular-nums`}
                                        >
                                            {money(item.available_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    {can.purchases && (
                        <form
                            className="grid gap-3 md:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                credit.post(`${base}/supplier-credits`, {
                                    preserveScroll: true,
                                    onSuccess: () => credit.reset(),
                                });
                            }}
                        >
                            <Label text="Proveedor">
                                <select
                                    className={field}
                                    value={credit.data.supplier_id}
                                    onChange={(event) =>
                                        credit.setData(
                                            'supplier_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Elige</option>
                                    {suppliers.map((supplier) => (
                                        <option
                                            key={supplier.id}
                                            value={supplier.id}
                                        >
                                            {supplier.name}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                            <Label text="Monto con IVA">
                                <input
                                    className={field}
                                    inputMode="decimal"
                                    value={credit.data.amount}
                                    onChange={(event) =>
                                        credit.setData(
                                            'amount',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Fecha">
                                <input
                                    type="date"
                                    className={field}
                                    value={credit.data.issued_on}
                                    onChange={(event) =>
                                        credit.setData(
                                            'issued_on',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Cuenta que disminuye">
                                <select
                                    className={field}
                                    value={credit.data.account_id}
                                    onChange={(event) =>
                                        credit.setData(
                                            'account_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Elige</option>
                                    {accounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.code} · {account.name}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                            <Label text="Motivo">
                                <input
                                    className={field}
                                    value={credit.data.reason}
                                    placeholder="Devolución de mercadería"
                                    onChange={(event) =>
                                        credit.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Serie de la nota">
                                <input
                                    className={field}
                                    value={credit.data.fiscal_series}
                                    onChange={(event) =>
                                        credit.setData(
                                            'fiscal_series',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Número de la nota">
                                <input
                                    className={field}
                                    value={credit.data.fiscal_number}
                                    onChange={(event) =>
                                        credit.setData(
                                            'fiscal_number',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <label className="flex items-end gap-2 pb-3 text-sm">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-[#640000]"
                                    checked={credit.data.with_vat}
                                    onChange={(event) =>
                                        credit.setData(
                                            'with_vat',
                                            event.target.checked,
                                        )
                                    }
                                />
                                Incluye IVA (disminuye el crédito fiscal)
                            </label>
                            <div>
                                <button
                                    className={primary}
                                    disabled={credit.processing}
                                >
                                    Registrar saldo a favor
                                </button>
                            </div>
                        </form>
                    )}
                    <Errors errors={credit.errors as Record<string, string>} />
                </Card>
            ) : (
                <>
                    <Card
                        title={
                            tabs.find((item) => item.key === tab)?.label ?? ''
                        }
                    >
                        {rows.length === 0 ? (
                            <Empty>Aún no hay documentos.</Empty>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[860px] text-left">
                                    <thead>
                                        <tr>
                                            <th className={head}>Número</th>
                                            <th className={head}>
                                                {isPurchase
                                                    ? 'Proveedor'
                                                    : 'Cliente'}
                                            </th>
                                            <th className={head}>Fecha</th>
                                            <th
                                                className={`${head} text-right`}
                                            >
                                                Total
                                            </th>
                                            <th className={head}>Estado</th>
                                            <th className={head} />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((document) => (
                                            <DocumentRow
                                                key={document.public_id}
                                                document={document}
                                                base={base}
                                                company={company.public_id}
                                                statuses={statuses}
                                                canManage={canManage}
                                            />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                    {canManage && (
                        <Card title={`Nueva ${kinds[tab]?.toLowerCase()}`}>
                            <form
                                className="space-y-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.transform((data) => ({
                                        ...data,
                                        kind: tab,
                                    }));
                                    form.post(base, {
                                        preserveScroll: true,
                                        onSuccess: () => form.reset(),
                                    });
                                }}
                            >
                                <div className="grid gap-3 md:grid-cols-3">
                                    <Label
                                        text={
                                            isPurchase ? 'Proveedor' : 'Cliente'
                                        }
                                    >
                                        <select
                                            className={field}
                                            value={form.data.counterparty_id}
                                            onChange={(event) =>
                                                form.setData(
                                                    'counterparty_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">Elige</option>
                                            {(isPurchase
                                                ? suppliers
                                                : customers
                                            ).map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id}
                                                >
                                                    {item.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Label>
                                    <Label text="Fecha">
                                        <input
                                            type="date"
                                            className={field}
                                            value={form.data.issued_on}
                                            onChange={(event) =>
                                                form.setData(
                                                    'issued_on',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                    <Label text="Vigente hasta (opcional)">
                                        <input
                                            type="date"
                                            className={field}
                                            value={form.data.valid_until}
                                            onChange={(event) =>
                                                form.setData(
                                                    'valid_until',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                </div>
                                {form.data.lines.map((line, index) => (
                                    <div
                                        key={index}
                                        className={`grid gap-2 ${isPurchase ? 'md:grid-cols-[2fr_90px_1fr_1fr_2fr_auto]' : 'md:grid-cols-[2fr_90px_1fr_1fr_auto]'}`}
                                    >
                                        <input
                                            className={field}
                                            placeholder="Descripción"
                                            value={line.description}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    description:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                        <input
                                            type="number"
                                            min={1}
                                            className={field}
                                            value={line.quantity}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    quantity: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                        />
                                        <input
                                            className={field}
                                            inputMode="decimal"
                                            placeholder="Precio con IVA"
                                            value={line.unit_price}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    unit_price:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                        <select
                                            className={field}
                                            value={line.tax_treatment}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    tax_treatment:
                                                        event.target.value,
                                                })
                                            }
                                        >
                                            <option value="goods">Bien</option>
                                            <option value="services">
                                                Servicio
                                            </option>
                                            <option value="exempt">
                                                Exento
                                            </option>
                                        </select>
                                        {isPurchase && (
                                            <select
                                                className={field}
                                                value={line.account_id}
                                                onChange={(event) =>
                                                    setLine(index, {
                                                        account_id:
                                                            event.target.value,
                                                    })
                                                }
                                            >
                                                <option value="">
                                                    Cuenta de gasto
                                                </option>
                                                {accounts
                                                    .filter(
                                                        (account) =>
                                                            account.type !==
                                                            'income',
                                                    )
                                                    .map((account) => (
                                                        <option
                                                            key={account.id}
                                                            value={account.id}
                                                        >
                                                            {account.code} ·{' '}
                                                            {account.name}
                                                        </option>
                                                    ))}
                                            </select>
                                        )}
                                        <button
                                            type="button"
                                            className={`${secondary} mt-1`}
                                            aria-label="Quitar línea"
                                            onClick={() =>
                                                form.setData(
                                                    'lines',
                                                    form.data.lines.filter(
                                                        (_, position) =>
                                                            position !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    className={secondary}
                                    onClick={() =>
                                        form.setData('lines', [
                                            ...form.data.lines,
                                            emptyLine(),
                                        ])
                                    }
                                >
                                    Agregar línea
                                </button>
                                <textarea
                                    className={field}
                                    rows={2}
                                    placeholder="Notas o condiciones (opcional)"
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                />
                                <Errors
                                    errors={
                                        form.errors as Record<string, string>
                                    }
                                />
                                <button
                                    className={primary}
                                    disabled={form.processing}
                                >
                                    Crear {kinds[tab]?.toLowerCase()}
                                </button>
                            </form>
                        </Card>
                    )}
                </>
            )}
        </PageShell>
    );
}
