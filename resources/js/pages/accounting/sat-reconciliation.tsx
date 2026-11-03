import { router, useForm } from '@inertiajs/react';
import { FileCheck2 } from 'lucide-react';
import { useState } from 'react';
import {
    Badge,
    cell,
    Empty,
    head,
    Label,
    PageShell,
    Stat,
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

type Issue = {
    kind: string;
    severity: string;
    direction: string;
    authorization: string | null;
    document: string;
    counterparty: string;
    date: string;
    sat_total_cents: number | null;
    books_total_cents: number | null;
    message: string;
};

type PackageItem = {
    id: number;
    name: string;
    reference_period: string;
    due_on: string;
    status: string;
    amount_cents: number | null;
    access_number: string | null;
    filing_reference: string | null;
    has_evidence: boolean;
};

type Props = {
    company: Company;
    year: number;
    month: number;
    comparison: {
        period: string;
        has_received: boolean;
        has_issued: boolean;
        sat: { received: number; issued: number; annulled: number };
        summary: Record<string, number>;
        issues: Issue[];
        registrable: number;
    };
    kinds: Record<string, string>;
    package: {
        period: string;
        items: PackageItem[];
        total_cents: number;
        due_on: string | null;
        ready: boolean;
        sent: {
            sent_at: string;
            recipients: number;
            total_cents: number;
        } | null;
    };
    accounts: { id: number; code: string; name: string }[];
    lastImport: { at: string; by: string | null } | null;
    canImport: boolean;
    canManageFiscal: boolean;
};

const months = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

const severityTone: Record<string, 'red' | 'amber' | 'slate'> = {
    critical: 'red',
    high: 'red',
    medium: 'amber',
    low: 'slate',
};

function PaymentRow({
    item,
    base,
    period,
    canManage,
}: {
    item: PackageItem;
    base: string;
    period: { year: number; month: number };
    canManage: boolean;
}) {
    const form = useForm({
        amount:
            item.amount_cents === null
                ? ''
                : (item.amount_cents / 100).toFixed(2),
        access_number: item.access_number ?? '',
    });

    return (
        <tr className="border-t border-slate-100 align-top">
            <td className={cell}>
                <span className="block font-semibold">{item.name}</span>
                <span className="text-xs text-slate-500">
                    {item.reference_period}
                    {item.filing_reference &&
                        ` · Presentada (${item.filing_reference})`}
                </span>
            </td>
            <td className={`${cell} whitespace-nowrap`}>
                {shortDate(item.due_on)}
            </td>
            <td className={cell} colSpan={canManage ? 1 : 2}>
                {canManage ? (
                    <form
                        className="flex flex-wrap items-center gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.patch(
                                `${base}/obligations/${item.id}?year=${period.year}&month=${period.month}`,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <input
                            className={`${field} !mt-0 w-32`}
                            inputMode="decimal"
                            placeholder="Monto Q"
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                        />
                        <input
                            className={`${field} !mt-0 w-44`}
                            placeholder="Número de acceso"
                            value={form.data.access_number}
                            onChange={(event) =>
                                form.setData(
                                    'access_number',
                                    event.target.value,
                                )
                            }
                        />
                        <button
                            className={secondary}
                            disabled={
                                form.processing || form.data.amount === ''
                            }
                        >
                            Guardar
                        </button>
                    </form>
                ) : item.amount_cents === null ? (
                    'Sin monto'
                ) : (
                    money(item.amount_cents)
                )}
            </td>
            <td className={cell}>
                {item.amount_cents === null ? (
                    <Badge tone="amber">Falta el monto</Badge>
                ) : item.amount_cents === 0 ? (
                    <Badge>Sin pago</Badge>
                ) : (
                    <Badge tone="green">{money(item.amount_cents)}</Badge>
                )}
            </td>
        </tr>
    );
}

export default function SatReconciliation({
    company,
    year,
    month,
    comparison,
    kinds,
    package: payment,
    accounts,
    lastImport,
    canImport,
    canManageFiscal,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/sat-reconciliation`;
    const query = `year=${year}&month=${month}`;
    const [filter, setFilter] = useState('');
    const upload = useForm<{ files: File[] }>({ files: [] });
    const defaultAccount = accounts.find((account) =>
        account.code.startsWith('5'),
    );
    const register = useForm({
        account_id: defaultAccount ? String(defaultAccount.id) : '',
        tax_treatment: 'goods',
    });
    const send = useForm({});
    const issues = comparison.issues.filter(
        (issue) => filter === '' || issue.kind === filter,
    );
    const problems =
        (comparison.summary.missing_in_books ?? 0) +
        (comparison.summary.annulled_in_sat ?? 0) +
        (comparison.summary.amount_mismatch ?? 0) +
        (comparison.summary.not_in_sat ?? 0);

    return (
        <PageShell
            company={company}
            title="Cruce con la SAT"
            subtitle="DTE del mes, compras faltantes, diferencias con la SAT y paquete de pago del cliente"
            icon={FileCheck2}
            actions={
                <form
                    className="flex items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const data = new FormData(event.currentTarget);
                        router.get(
                            base,
                            {
                                year: String(data.get('year')),
                                month: String(data.get('month')),
                            },
                            { preserveState: true },
                        );
                    }}
                >
                    <select
                        name="month"
                        defaultValue={month}
                        className={`${field} !mt-0 w-36`}
                    >
                        {months.map((name, index) => (
                            <option key={name} value={index + 1}>
                                {name}
                            </option>
                        ))}
                    </select>
                    <input
                        name="year"
                        type="number"
                        min={2000}
                        max={2100}
                        defaultValue={year}
                        className={`${field} !mt-0 w-24`}
                    />
                    <button className={secondary}>Ver mes</button>
                </form>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                    label="Recibidos según la SAT"
                    value={comparison.sat.received}
                />
                <Stat
                    label="Emitidos según la SAT"
                    value={comparison.sat.issued}
                />
                <Stat
                    label="Anulados en la SAT"
                    value={comparison.sat.annulled}
                />
                <Stat
                    label="Diferencias"
                    value={problems}
                    tone={
                        comparison.has_received || comparison.has_issued
                            ? problems === 0
                                ? 'good'
                                : 'bad'
                            : 'default'
                    }
                />
            </div>

            {canImport && (
                <Card title="1. Cargar los DTE del mes">
                    <p className="mb-3 text-sm text-slate-600">
                        En la Agencia Virtual de la SAT entra a Factura
                        Electrónica &gt; Consultar DTE, filtra el mes y descarga
                        el listado de documentos <b>recibidos</b> y el de{' '}
                        <b>emitidos</b> (CSV o Excel). También puedes subir los
                        XML o un ZIP con ellos. Si vuelves a cargar el listado,
                        se actualizan los documentos anulados.
                    </p>
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            upload.post(`${base}/import?${query}`, {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => upload.reset(),
                            });
                        }}
                    >
                        <input
                            key={upload.data.files.length}
                            type="file"
                            multiple
                            accept=".csv,.txt,.xlsx,.xls,.xml,.zip"
                            className="block text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold"
                            onChange={(event) =>
                                upload.setData(
                                    'files',
                                    Array.from(event.target.files ?? []),
                                )
                            }
                        />
                        <button
                            className={primary}
                            disabled={
                                upload.processing ||
                                upload.data.files.length === 0
                            }
                        >
                            {upload.processing ? 'Cargando…' : 'Cargar DTE'}
                        </button>
                    </form>
                    {lastImport && (
                        <p className="mt-2 text-xs text-slate-500">
                            Última carga: {shortDate(lastImport.at)}
                            {lastImport.by && ` por ${lastImport.by}`}
                        </p>
                    )}
                    <Errors errors={upload.errors as Record<string, string>} />
                </Card>
            )}

            {canImport && comparison.registrable > 0 && (
                <Card title="2. Registrar las compras que faltan">
                    <p className="mb-3 text-sm text-slate-600">
                        {comparison.registrable} factura(s) recibida(s) no están
                        en las compras. Se registran en borrador con su
                        proveedor (se crea si no existe), su total y el IVA
                        según su tipo; revísalas y apruébalas para que entren al
                        Libro de Compras.
                    </p>
                    <form
                        className="grid gap-3 md:grid-cols-[2fr_1fr_auto] md:items-end"
                        onSubmit={(event) => {
                            event.preventDefault();
                            register.post(`${base}/purchases?${query}`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Label text="Cuenta de gasto o activo">
                            <select
                                className={field}
                                value={register.data.account_id}
                                onChange={(event) =>
                                    register.setData(
                                        'account_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Elige una cuenta</option>
                                {accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.code} · {account.name}
                                    </option>
                                ))}
                            </select>
                        </Label>
                        <Label text="Tratamiento del IVA">
                            <select
                                className={field}
                                value={register.data.tax_treatment}
                                onChange={(event) =>
                                    register.setData(
                                        'tax_treatment',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="goods">Bienes</option>
                                <option value="services">Servicios</option>
                                <option value="exempt">Exento</option>
                            </select>
                        </Label>
                        <button
                            className={primary}
                            disabled={
                                register.processing ||
                                register.data.account_id === ''
                            }
                        >
                            Registrar {comparison.registrable} compra(s)
                        </button>
                    </form>
                    <Errors
                        errors={register.errors as Record<string, string>}
                    />
                </Card>
            )}

            <Card title="3. Diferencias con la SAT">
                {!comparison.has_received && !comparison.has_issued ? (
                    <Empty>
                        Aún no se cargaron los DTE de este mes. Carga los
                        listados de la SAT para comparar.
                    </Empty>
                ) : (
                    <>
                        <div className="mb-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                className={filter === '' ? primary : secondary}
                                onClick={() => setFilter('')}
                            >
                                Todas ({comparison.issues.length})
                            </button>
                            {Object.entries(kinds).map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    className={
                                        filter === key ? primary : secondary
                                    }
                                    onClick={() => setFilter(key)}
                                >
                                    {label} ({comparison.summary[key] ?? 0})
                                </button>
                            ))}
                        </div>
                        {!comparison.has_issued && (
                            <p className="mb-2 text-xs text-amber-700">
                                No se cargaron los DTE emitidos: las ventas no
                                se compararon.
                            </p>
                        )}
                        {!comparison.has_received && (
                            <p className="mb-2 text-xs text-amber-700">
                                No se cargaron los DTE recibidos: las compras no
                                se compararon.
                            </p>
                        )}
                        {issues.length === 0 ? (
                            <Empty>
                                Todo cuadra: los libros coinciden con lo que
                                reporta la SAT.
                            </Empty>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[960px] text-left">
                                    <thead>
                                        <tr>
                                            <th className={head}>Diferencia</th>
                                            <th className={head}>Documento</th>
                                            <th className={head}>
                                                Emisor o receptor
                                            </th>
                                            <th className={head}>Fecha</th>
                                            <th
                                                className={`${head} text-right`}
                                            >
                                                SAT
                                            </th>
                                            <th
                                                className={`${head} text-right`}
                                            >
                                                Libros
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {issues.map((issue, index) => (
                                            <tr
                                                key={`${issue.authorization}-${index}`}
                                                className="border-t border-slate-100 align-top"
                                            >
                                                <td className={cell}>
                                                    <Badge
                                                        tone={
                                                            severityTone[
                                                                issue.severity
                                                            ] ?? 'slate'
                                                        }
                                                    >
                                                        {kinds[issue.kind] ??
                                                            issue.kind}
                                                    </Badge>
                                                    <span className="mt-1 block text-xs text-slate-600">
                                                        {issue.message}
                                                    </span>
                                                </td>
                                                <td className={cell}>
                                                    <span className="block font-semibold">
                                                        {issue.document}
                                                    </span>
                                                    <span className="text-xs text-slate-500">
                                                        {issue.direction ===
                                                        'issued'
                                                            ? 'Emitido'
                                                            : 'Recibido'}
                                                    </span>
                                                </td>
                                                <td className={cell}>
                                                    {issue.counterparty}
                                                </td>
                                                <td
                                                    className={`${cell} whitespace-nowrap`}
                                                >
                                                    {shortDate(issue.date)}
                                                </td>
                                                <td
                                                    className={`${cell} text-right tabular-nums`}
                                                >
                                                    {issue.sat_total_cents ===
                                                    null
                                                        ? '—'
                                                        : money(
                                                              issue.sat_total_cents,
                                                          )}
                                                </td>
                                                <td
                                                    className={`${cell} text-right tabular-nums`}
                                                >
                                                    {issue.books_total_cents ===
                                                    null
                                                        ? '—'
                                                        : money(
                                                              issue.books_total_cents,
                                                          )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </>
                )}
            </Card>

            <Card title="4. Paquete de pago para el cliente">
                <p className="mb-3 text-sm text-slate-600">
                    Indica cuánto paga el cliente por cada impuesto del mes (0
                    si no hay pago) y el número de acceso del formulario. Al
                    enviar, el cliente recibe en su panel y por correo cuánto
                    paga y antes de qué día, y descarga las constancias desde su
                    portal.
                </p>
                {payment.items.length === 0 ? (
                    <Empty>
                        No hay obligaciones fiscales de este mes. Genéralas
                        desde el calendario fiscal.
                    </Empty>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left">
                                <thead>
                                    <tr>
                                        <th className={head}>Obligación</th>
                                        <th className={head}>Vence</th>
                                        <th className={head}>
                                            Monto y número de acceso
                                        </th>
                                        <th className={head}>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {payment.items.map((item) => (
                                        <PaymentRow
                                            key={`${item.id}-${item.amount_cents}-${item.access_number}`}
                                            item={item}
                                            base={base}
                                            period={{ year, month }}
                                            canManage={canManageFiscal}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-[#fbf4f4] p-4">
                            <div>
                                <p className="text-sm text-slate-600">
                                    Total a pagar este mes
                                </p>
                                <p className="text-xl font-semibold">
                                    {money(payment.total_cents)}
                                    {payment.due_on &&
                                        ` antes del ${shortDate(payment.due_on)}`}
                                </p>
                                {payment.sent && (
                                    <p className="text-xs text-slate-500">
                                        Enviado el{' '}
                                        {shortDate(payment.sent.sent_at)} a{' '}
                                        {payment.sent.recipients} contacto(s)
                                        por {money(payment.sent.total_cents)}
                                    </p>
                                )}
                            </div>
                            {canManageFiscal && (
                                <button
                                    type="button"
                                    className={primary}
                                    disabled={send.processing || !payment.ready}
                                    title={
                                        payment.ready
                                            ? undefined
                                            : 'Indica el monto de cada obligación'
                                    }
                                    onClick={() =>
                                        send.post(`${base}/package?${query}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    {payment.sent
                                        ? 'Enviar de nuevo al cliente'
                                        : 'Enviar paquete al cliente'}
                                </button>
                            )}
                        </div>
                        <Errors
                            errors={send.errors as Record<string, string>}
                        />
                    </>
                )}
            </Card>
        </PageShell>
    );
}
