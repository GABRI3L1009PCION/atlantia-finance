import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import {
    FilterChips,
    amount,
    dueLabel,
    priorityLabels,
    usePayablesHref,
} from '@/components/payables/kit';
import type {
    Payable,
    PayablesContext,
    PayablesPermissions,
    Proposal,
} from '@/components/payables/kit';
import { ProposalsTable } from '@/components/payables/tables';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Line = {
    kind: 'invoice' | 'advance';
    purchase_document_id: number | null;
    supplier_id: number | null;
    amount: string;
    selected: boolean;
};

function ProposalForm({
    companyId,
    companies,
    candidates,
    suppliers,
    preselected,
}: {
    companyId: number;
    companies: { id: number; name: string }[];
    candidates: Payable[];
    suppliers: { id: number; name: string }[];
    preselected: number | null;
}) {
    const href = usePayablesHref();
    const available = (document: Payable) =>
        document.outstanding_cents - document.committed_cents;
    const form = useForm<{
        company_id: number;
        proposed_date: string;
        priority: string;
        notes: string;
        lines: Line[];
    }>({
        company_id: companyId,
        proposed_date: new Date().toISOString().slice(0, 10),
        priority: 'normal',
        notes: '',
        lines: candidates.map((document) => ({
            kind: 'invoice',
            purchase_document_id: document.id,
            supplier_id: document.supplier_id,
            amount: (available(document) / 100).toFixed(2),
            selected: document.id === preselected,
        })),
    });
    const setLine = (index: number, changes: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((line, position) =>
                position === index ? { ...line, ...changes } : line,
            ),
        );
    const chosen = form.data.lines.filter((line) => line.selected);
    const total = chosen.reduce(
        (sum, line) => sum + Math.round(Number(line.amount || 0) * 100),
        0,
    );

    return (
        <form
            className="grid gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((values) => ({
                    company_id: values.company_id,
                    proposed_date: values.proposed_date,
                    priority: values.priority,
                    notes: values.notes || null,
                    items: values.lines
                        .filter((line) => line.selected)
                        .map((line) => ({
                            kind: line.kind,
                            purchase_document_id: line.purchase_document_id,
                            supplier_id: line.supplier_id,
                            amount: line.amount,
                        })),
                }));
                form.post('/payables/proposals', { preserveScroll: true });
            }}
        >
            <div className="grid gap-2 md:grid-cols-4">
                <label className="grid gap-1 text-xs font-semibold">
                    Empresa
                    <select
                        className={input}
                        value={companyId}
                        onChange={(event) =>
                            router.get(href('/payables/proposals'), {
                                company_id: event.target.value,
                            })
                        }
                    >
                        {companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Fecha propuesta de pago
                    <input
                        type="date"
                        className={input}
                        value={form.data.proposed_date}
                        onChange={(event) =>
                            form.setData('proposed_date', event.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Prioridad
                    <select
                        className={input}
                        value={form.data.priority}
                        onChange={(event) =>
                            form.setData('priority', event.target.value)
                        }
                    >
                        {Object.entries(priorityLabels).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold">
                    Notas
                    <input
                        className={input}
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                    />
                </label>
            </div>
            <Table
                headers={[
                    '',
                    'Documento / anticipo',
                    'Proveedor',
                    'Vencimiento',
                    'Disponible',
                    'Monto a pagar (Q)',
                ]}
                empty={
                    form.data.lines.length === 0 &&
                    'La empresa no tiene documentos con saldo disponible para proponer.'
                }
            >
                {form.data.lines.map((line, index) => {
                    const document = candidates.find(
                        (row) => row.id === line.purchase_document_id,
                    );
                    const [label, tone] = document
                        ? dueLabel(document)
                        : ['Anticipo', 'violet'];

                    return (
                        <tr key={index}>
                            <td className="px-2 py-2">
                                <input
                                    type="checkbox"
                                    aria-label="Incluir"
                                    checked={line.selected}
                                    onChange={(event) =>
                                        setLine(index, {
                                            selected: event.target.checked,
                                        })
                                    }
                                />
                            </td>
                            <td className="px-2 py-2 font-bold">
                                {document ? document.document : 'Anticipo'}
                                {document?.in_proposal && (
                                    <span
                                        className={`ml-1 ${toneBadge('blue')}`}
                                    >
                                        En otra propuesta
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {document ? (
                                    document.supplier
                                ) : (
                                    <select
                                        aria-label="Proveedor"
                                        className={`${input} h-8 text-xs`}
                                        value={line.supplier_id ?? ''}
                                        onChange={(event) =>
                                            setLine(index, {
                                                supplier_id: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                    >
                                        {suppliers.map((supplier) => (
                                            <option
                                                key={supplier.id}
                                                value={supplier.id}
                                            >
                                                {supplier.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                <span className={toneBadge(tone)}>{label}</span>
                            </td>
                            <td className="px-2 py-2">
                                {document ? amount(available(document)) : '—'}
                            </td>
                            <td className="px-2 py-2">
                                <input
                                    aria-label="Monto"
                                    className={`${input} h-8 w-32 text-xs`}
                                    inputMode="decimal"
                                    value={line.amount}
                                    onChange={(event) =>
                                        setLine(index, {
                                            amount: event.target.value,
                                        })
                                    }
                                />
                            </td>
                        </tr>
                    );
                })}
            </Table>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <button
                    type="button"
                    className={secondaryButton}
                    disabled={suppliers.length === 0}
                    onClick={() =>
                        form.setData('lines', [
                            ...form.data.lines,
                            {
                                kind: 'advance',
                                purchase_document_id: null,
                                supplier_id: suppliers[0]?.id ?? null,
                                amount: '',
                                selected: true,
                            },
                        ])
                    }
                >
                    Agregar anticipo a proveedor
                </button>
                <span className="text-sm font-bold text-[#061633]">
                    {chosen.length} línea(s) · Total {amount(total)}
                </span>
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={form.processing || chosen.length === 0}
                >
                    Crear propuesta (borrador)
                </button>
            </div>
            {Object.values(form.errors).length > 0 && (
                <p className="text-xs text-red-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

export default function PayablesProposals({
    context,
    proposals,
    filters,
    formCompanyId,
    candidates,
    suppliers,
    permissions,
    notice,
}: {
    context: PayablesContext;
    proposals: Proposal[];
    filters: { status?: string };
    statuses: Record<string, string>;
    formCompanyId: number | null;
    candidates: Payable[];
    suppliers: { id: number; name: string }[];
    permissions: PayablesPermissions;
    notice: string | null;
}) {
    const href = usePayablesHref();
    const query = new URLSearchParams(usePage().url.split('?')[1] ?? '');
    const preselected = Number(query.get('document')) || null;

    return (
        <>
            <Head title="Propuestas de pago" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Propuestas de pago"
                    subtitle="CxP propone qué pagar, a quién, cuánto y cuándo; otra persona la aprueba y Tesorería decide cómo pagar y ejecuta. Una propuesta no reduce saldos"
                />
                {permissions.canPropose &&
                    permissions.canViewPanel &&
                    formCompanyId !== null && (
                        <Card title="Nueva propuesta">
                            <ProposalForm
                                key={`${formCompanyId}-${preselected ?? ''}`}
                                companyId={formCompanyId}
                                companies={context.companies}
                                candidates={candidates}
                                suppliers={suppliers}
                                preselected={preselected}
                            />
                        </Card>
                    )}
                <Card title={`${proposals.length} propuesta(s)`}>
                    <FilterChips
                        options={[
                            ['', 'Todas'],
                            ['active', 'En curso'],
                            ['pending_approval', 'Por aprobar'],
                            ['rejected', 'Rechazadas'],
                            ['completed', 'Completadas'],
                        ]}
                        current={filters.status ?? ''}
                        onSelect={(status) =>
                            router.get(href('/payables/proposals'), { status })
                        }
                    />
                    <ProposalsTable
                        proposals={proposals}
                        empty="No existen propuestas con este filtro."
                    />
                </Card>
            </Shell>
        </>
    );
}
