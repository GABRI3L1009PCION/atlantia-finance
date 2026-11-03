import { useForm } from '@inertiajs/react';
import { Receipt, Trash2 } from 'lucide-react';
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

type Report = {
    public_id: string;
    number: number;
    employee_name: string;
    purpose: string;
    status: string;
    total_cents: number;
    submitted_by: number | null;
    rejection_reason: string | null;
    reimbursed_on: string | null;
    reimbursement_reference: string | null;
    lines: {
        id: number;
        spent_on: string;
        description: string;
        supplier: string | null;
        document_reference: string | null;
        account: string;
        amount_cents: number;
        has_receipt: boolean;
    }[];
};

type Account = { id: number; code: string; name: string };

type Props = {
    company: Company;
    reports: Report[];
    statuses: Record<string, string>;
    accounts: Account[];
    liabilities: Account[];
    banks: {
        id: number;
        bank_name: string;
        account_name: string;
        last_four: string | null;
    }[];
    userId: number;
    can: { create: boolean; approve: boolean; reimburse: boolean };
};

type Line = {
    spent_on: string;
    description: string;
    supplier: string;
    document_reference: string;
    account_id: string;
    amount: string;
    receipt: File | null;
};

const today = () => new Date().toISOString().slice(0, 10);

const emptyLine = (): Line => ({
    spent_on: today(),
    description: '',
    supplier: '',
    document_reference: '',
    account_id: '',
    amount: '',
    receipt: null,
});

const statusTone: Record<string, 'slate' | 'green' | 'amber' | 'red'> = {
    draft: 'slate',
    submitted: 'amber',
    approved: 'amber',
    rejected: 'red',
    reimbursed: 'green',
};

function ReportCard({
    report,
    base,
    statuses,
    liabilities,
    banks,
    userId,
    can,
}: {
    report: Report;
    base: string;
    statuses: Record<string, string>;
    liabilities: Account[];
    banks: Props['banks'];
    userId: number;
    can: Props['can'];
}) {
    const action = useForm({
        payable_account_id: liabilities[0] ? String(liabilities[0].id) : '',
        reason: '',
        bank_account_id: banks[0] ? String(banks[0].id) : '',
        paid_on: today(),
        reference: '',
    });
    const [rejecting, setRejecting] = useState(false);
    const post = (name: string) =>
        action.post(`${base}/${report.public_id}/${name}`, {
            preserveScroll: true,
        });

    return (
        <section className="rounded-2xl border border-[#ece3e3] bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-semibold">
                        Liquidación {report.number} · {report.employee_name}
                    </h3>
                    <p className="text-sm text-slate-600">{report.purpose}</p>
                    {report.rejection_reason && (
                        <p className="text-xs text-red-700">
                            Rechazada: {report.rejection_reason}
                        </p>
                    )}
                    {report.reimbursed_on && (
                        <p className="text-xs text-slate-500">
                            Reembolsada el {shortDate(report.reimbursed_on)} ·{' '}
                            {report.reimbursement_reference}
                        </p>
                    )}
                </div>
                <div className="text-right">
                    <Badge tone={statusTone[report.status] ?? 'slate'}>
                        {statuses[report.status] ?? report.status}
                    </Badge>
                    <p className="mt-1 text-lg font-semibold">
                        {money(report.total_cents)}
                    </p>
                </div>
            </div>
            <table className="mt-3 w-full text-left">
                <thead>
                    <tr>
                        <th className={head}>Fecha</th>
                        <th className={head}>Gasto</th>
                        <th className={head}>Cuenta</th>
                        <th className={`${head} text-right`}>Monto</th>
                    </tr>
                </thead>
                <tbody>
                    {report.lines.map((line) => (
                        <tr key={line.id} className="border-t border-slate-100">
                            <td className={cell}>{shortDate(line.spent_on)}</td>
                            <td className={cell}>
                                {line.description}
                                {(line.supplier || line.document_reference) && (
                                    <span className="block text-xs text-slate-500">
                                        {[
                                            line.supplier,
                                            line.document_reference,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                )}
                                {line.has_receipt && (
                                    <a
                                        className="text-xs font-semibold text-[#640000] underline"
                                        href={`${base}/receipts/${line.id}`}
                                    >
                                        Comprobante
                                    </a>
                                )}
                            </td>
                            <td className={cell}>{line.account}</td>
                            <td className={`${cell} text-right tabular-nums`}>
                                {money(line.amount_cents)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="mt-3 flex flex-wrap items-end gap-2">
                {can.create &&
                    ['draft', 'rejected'].includes(report.status) && (
                        <button
                            type="button"
                            className={primary}
                            onClick={() => post('submit')}
                        >
                            Enviar a aprobación
                        </button>
                    )}
                {can.approve && report.status === 'submitted' && (
                    <>
                        {report.submitted_by === userId ? (
                            <span className="text-xs text-slate-500">
                                La aprueba otra persona: tú la enviaste.
                            </span>
                        ) : (
                            <>
                                <Label text="Queda por pagar en">
                                    <select
                                        className={field}
                                        value={action.data.payable_account_id}
                                        onChange={(event) =>
                                            action.setData(
                                                'payable_account_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {liabilities.map((account) => (
                                            <option
                                                key={account.id}
                                                value={account.id}
                                            >
                                                {account.code} · {account.name}
                                            </option>
                                        ))}
                                    </select>
                                </Label>
                                <button
                                    type="button"
                                    className={primary}
                                    onClick={() => post('approve')}
                                >
                                    Aprobar
                                </button>
                            </>
                        )}
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => setRejecting(!rejecting)}
                        >
                            Rechazar
                        </button>
                    </>
                )}
                {can.reimburse && report.status === 'approved' && (
                    <>
                        <Label text="Desde la cuenta">
                            <select
                                className={field}
                                value={action.data.bank_account_id}
                                onChange={(event) =>
                                    action.setData(
                                        'bank_account_id',
                                        event.target.value,
                                    )
                                }
                            >
                                {banks.map((bank) => (
                                    <option key={bank.id} value={bank.id}>
                                        {bank.bank_name} · {bank.account_name}
                                        {bank.last_four &&
                                            ` · ${bank.last_four}`}
                                    </option>
                                ))}
                            </select>
                        </Label>
                        <Label text="Fecha">
                            <input
                                type="date"
                                className={field}
                                value={action.data.paid_on}
                                onChange={(event) =>
                                    action.setData(
                                        'paid_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </Label>
                        <Label text="Referencia">
                            <input
                                className={field}
                                placeholder="Transferencia o cheque"
                                value={action.data.reference}
                                onChange={(event) =>
                                    action.setData(
                                        'reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </Label>
                        <button
                            type="button"
                            className={primary}
                            onClick={() => post('reimburse')}
                        >
                            Registrar reembolso
                        </button>
                    </>
                )}
            </div>
            {rejecting && (
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <Label text="Motivo del rechazo">
                        <input
                            className={field}
                            value={action.data.reason}
                            onChange={(event) =>
                                action.setData('reason', event.target.value)
                            }
                        />
                    </Label>
                    <button
                        type="button"
                        className={secondary}
                        onClick={() => post('reject')}
                    >
                        Confirmar rechazo
                    </button>
                </div>
            )}
            <Errors errors={action.errors as Record<string, string>} />
        </section>
    );
}

export default function Expenses({
    company,
    reports,
    statuses,
    accounts,
    liabilities,
    banks,
    userId,
    can,
}: Props) {
    const base = `/accounting/companies/${company.public_id}/expenses`;
    const form = useForm<{
        employee_name: string;
        purpose: string;
        lines: Line[];
    }>({ employee_name: '', purpose: '', lines: [emptyLine()] });
    const setLine = (index: number, values: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((line, position) =>
                position === index ? { ...line, ...values } : line,
            ),
        );

    return (
        <PageShell
            company={company}
            title="Gastos de empleados"
            subtitle="Liquidaciones de gastos con comprobante, aprobación y reembolso"
            icon={Receipt}
        >
            {can.create && (
                <Card title="Nueva liquidación de gastos">
                    <p className="mb-3 text-sm text-slate-600">
                        Lo que un empleado pagó de su bolsa por la empresa
                        (combustible, viáticos, parqueos). Las facturas que dan
                        crédito fiscal regístralas en Compras.
                    </p>
                    <form
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(base, {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <div className="grid gap-3 md:grid-cols-2">
                            <Label text="Empleado">
                                <input
                                    className={field}
                                    value={form.data.employee_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'employee_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                            <Label text="Motivo">
                                <input
                                    className={field}
                                    placeholder="Visita a clientes de Quetzaltenango"
                                    value={form.data.purpose}
                                    onChange={(event) =>
                                        form.setData(
                                            'purpose',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Label>
                        </div>
                        {form.data.lines.map((line, index) => (
                            <div
                                key={index}
                                className="grid gap-2 rounded-xl border border-slate-100 p-3 md:grid-cols-4"
                            >
                                <input
                                    type="date"
                                    className={field}
                                    value={line.spent_on}
                                    onChange={(event) =>
                                        setLine(index, {
                                            spent_on: event.target.value,
                                        })
                                    }
                                />
                                <input
                                    className={field}
                                    placeholder="Descripción"
                                    value={line.description}
                                    onChange={(event) =>
                                        setLine(index, {
                                            description: event.target.value,
                                        })
                                    }
                                />
                                <select
                                    className={field}
                                    value={line.account_id}
                                    onChange={(event) =>
                                        setLine(index, {
                                            account_id: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">Cuenta de gasto</option>
                                    {accounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.code} · {account.name}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    className={field}
                                    inputMode="decimal"
                                    placeholder="Monto"
                                    value={line.amount}
                                    onChange={(event) =>
                                        setLine(index, {
                                            amount: event.target.value,
                                        })
                                    }
                                />
                                <input
                                    className={field}
                                    placeholder="Proveedor (opcional)"
                                    value={line.supplier}
                                    onChange={(event) =>
                                        setLine(index, {
                                            supplier: event.target.value,
                                        })
                                    }
                                />
                                <input
                                    className={field}
                                    placeholder="No. de recibo (opcional)"
                                    value={line.document_reference}
                                    onChange={(event) =>
                                        setLine(index, {
                                            document_reference:
                                                event.target.value,
                                        })
                                    }
                                />
                                <input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    className="mt-2 block text-xs text-slate-700 file:mr-2 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-semibold"
                                    onChange={(event) =>
                                        setLine(index, {
                                            receipt:
                                                event.target.files?.[0] ?? null,
                                        })
                                    }
                                />
                                <button
                                    type="button"
                                    className={`${secondary} mt-1`}
                                    aria-label="Quitar gasto"
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
                            Agregar gasto
                        </button>
                        <Errors
                            errors={form.errors as Record<string, string>}
                        />
                        <button className={primary} disabled={form.processing}>
                            Registrar liquidación
                        </button>
                    </form>
                </Card>
            )}
            {reports.length === 0 ? (
                <Empty>Aún no hay liquidaciones de gastos.</Empty>
            ) : (
                reports.map((report) => (
                    <ReportCard
                        key={report.public_id}
                        report={report}
                        base={base}
                        statuses={statuses}
                        liabilities={liabilities}
                        banks={banks}
                        userId={userId}
                        can={can}
                    />
                ))
            )}
        </PageShell>
    );
}
