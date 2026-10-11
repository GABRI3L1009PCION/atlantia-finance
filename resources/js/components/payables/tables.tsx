import { Link, router } from '@inertiajs/react';
import { formatDate, secondaryButton } from '@/components/office-admin/kit';
import {
    advanceTone,
    amount,
    documentTone,
    dueLabel,
    paymentTone,
    proposalTone,
    usePayablesHref,
} from '@/components/payables/kit';
import type {
    Advance,
    Payable,
    PaymentRow,
    Proposal,
    SupplierBalance,
} from '@/components/payables/kit';
import { Table, toneBadge } from '@/components/supervisor/kit';

/** Suppliers with balance, shared by the suppliers and company screens. */
export function SuppliersTable({
    suppliers,
    empty,
}: {
    suppliers: SupplierBalance[];
    empty: string;
}) {
    const href = usePayablesHref();

    return (
        <Table
            headers={[
                'Proveedor',
                'Documentos',
                'Saldo total',
                'Vencido',
                'Por vencer',
                'Próximo vencimiento',
                'Máx. días',
                'Último pago',
                'Anticipos',
                'Estado',
            ]}
            empty={suppliers.length === 0 && empty}
        >
            {suppliers.map((supplier) => (
                <tr key={supplier.id}>
                    <td className="px-2 py-2 font-bold">
                        <Link
                            href={href(supplier.destination)}
                            className="hover:text-[#640000]"
                        >
                            {supplier.name}
                        </Link>
                        <p className="text-[11px] font-normal text-[#526b92]">
                            {supplier.company} · NIT{' '}
                            {supplier.tax_id ?? 'No indicado'}
                            {supplier.payment_terms_days !== null &&
                                ` · ${supplier.payment_terms_days} días`}
                        </p>
                    </td>
                    <td className="px-2 py-2">{supplier.documents}</td>
                    <td className="px-2 py-2 font-semibold">
                        {amount(supplier.total_cents)}
                    </td>
                    <td className="px-2 py-2 text-red-700">
                        {amount(supplier.overdue_cents)}
                    </td>
                    <td className="px-2 py-2">
                        {amount(supplier.current_cents)}
                    </td>
                    <td className="px-2 py-2">
                        {formatDate(supplier.next_due_date)}
                    </td>
                    <td className="px-2 py-2">{supplier.max_overdue_days}</td>
                    <td className="px-2 py-2">
                        {supplier.last_payment
                            ? `${formatDate(supplier.last_payment.date)} · ${amount(supplier.last_payment.amount_cents)}`
                            : '—'}
                    </td>
                    <td className="px-2 py-2">
                        {supplier.advances_cents > 0
                            ? amount(supplier.advances_cents)
                            : '—'}
                    </td>
                    <td className="px-2 py-2">
                        <span
                            className={toneBadge(
                                supplier.status === 'overdue'
                                    ? 'red'
                                    : supplier.status === 'advance_only'
                                      ? 'violet'
                                      : 'green',
                            )}
                        >
                            {supplier.status_label}
                        </span>
                        {supplier.disputed && (
                            <span className={`ml-1 ${toneBadge('violet')}`}>
                                En disputa
                            </span>
                        )}
                    </td>
                </tr>
            ))}
        </Table>
    );
}

/** Documents payable table shared by several screens. */
export function DocumentsTable({
    documents,
    empty,
    showSupplier = true,
}: {
    documents: Payable[];
    empty: string;
    showSupplier?: boolean;
}) {
    const href = usePayablesHref();

    return (
        <Table
            headers={[
                'Documento',
                ...(showSupplier ? ['Proveedor'] : []),
                'Emisión',
                'Vence',
                'Original',
                'Pagado',
                'NC, anticipos y ajustes',
                'Saldo',
                'Vencimiento',
                'Estado',
            ]}
            empty={documents.length === 0 && empty}
        >
            {documents.map((document) => {
                const [label, tone] = dueLabel(document);

                return (
                    <tr key={document.id}>
                        <td className="px-2 py-2 font-bold">
                            <Link
                                href={href(document.destination)}
                                className="hover:text-[#640000]"
                            >
                                {document.document}
                            </Link>
                            {document.currency !== 'GTQ' && (
                                <span className={`ml-1 ${toneBadge('blue')}`}>
                                    {document.currency}
                                </span>
                            )}
                            {document.disputed && (
                                <span className={`ml-1 ${toneBadge('violet')}`}>
                                    En disputa
                                </span>
                            )}
                            {document.in_proposal && (
                                <span className={`ml-1 ${toneBadge('blue')}`}>
                                    En propuesta
                                </span>
                            )}
                            {document.committed_cents > 0 && (
                                <span className={`ml-1 ${toneBadge('amber')}`}>
                                    En Tesorería
                                </span>
                            )}
                        </td>
                        {showSupplier && (
                            <td className="px-2 py-2">
                                {document.supplier}
                                <p className="text-[11px] text-[#526b92]">
                                    {document.company}
                                </p>
                            </td>
                        )}
                        <td className="px-2 py-2">
                            {formatDate(document.issue_date)}
                        </td>
                        <td className="px-2 py-2">
                            {formatDate(document.due_date)}
                        </td>
                        <td className="px-2 py-2">
                            {amount(document.original_cents)}
                        </td>
                        <td className="px-2 py-2">
                            {amount(document.paid_cents)}
                        </td>
                        <td className="px-2 py-2">
                            {amount(
                                document.credited_cents +
                                    document.advances_cents +
                                    document.adjusted_cents,
                            )}
                        </td>
                        <td className="px-2 py-2 font-semibold">
                            {amount(document.outstanding_cents)}
                        </td>
                        <td className="px-2 py-2">
                            {document.outstanding_cents > 0 ? (
                                <span className={toneBadge(tone)}>{label}</span>
                            ) : (
                                '—'
                            )}
                        </td>
                        <td className="px-2 py-2">
                            <span
                                className={toneBadge(
                                    documentTone[document.status] ?? 'slate',
                                )}
                            >
                                {document.status_label}
                            </span>
                        </td>
                    </tr>
                );
            })}
        </Table>
    );
}

/** Payment proposals with their review state. */
export function ProposalsTable({
    proposals,
    empty,
}: {
    proposals: Proposal[];
    empty: string;
}) {
    const href = usePayablesHref();

    return (
        <Table
            headers={[
                'Referencia',
                'Empresa',
                'Fecha propuesta',
                'Proveedores',
                'Documentos',
                'Total',
                'Preparó',
                'Aprobó',
                'Estado',
            ]}
            empty={proposals.length === 0 && empty}
        >
            {proposals.map((proposal) => (
                <tr key={proposal.id}>
                    <td className="px-2 py-2 font-bold">
                        <Link
                            href={href(proposal.destination)}
                            className="hover:text-[#640000]"
                        >
                            {proposal.reference}
                        </Link>
                        {proposal.can_review && (
                            <span className={`ml-1 ${toneBadge('amber')}`}>
                                Te toca revisar
                            </span>
                        )}
                    </td>
                    <td className="px-2 py-2">{proposal.company}</td>
                    <td className="px-2 py-2">
                        {formatDate(proposal.proposed_date)}
                    </td>
                    <td className="px-2 py-2">{proposal.suppliers_count}</td>
                    <td className="px-2 py-2">
                        {proposal.documents_count}
                        {proposal.advances_count > 0 &&
                            ` + ${proposal.advances_count} anticipo(s)`}
                    </td>
                    <td className="px-2 py-2 font-semibold">
                        {amount(proposal.total_cents)}
                    </td>
                    <td className="px-2 py-2">{proposal.preparer ?? '—'}</td>
                    <td className="px-2 py-2">{proposal.approver ?? '—'}</td>
                    <td className="px-2 py-2">
                        <span
                            className={toneBadge(
                                proposalTone[proposal.status] ?? 'slate',
                            )}
                        >
                            {proposal.status_label}
                        </span>
                    </td>
                </tr>
            ))}
        </Table>
    );
}

/** Supplier payments (Treasury's shared payment entity plus legacy payments from Purchases). */
export function PaymentsTable({
    payments,
    empty,
}: {
    payments: PaymentRow[];
    empty: string;
}) {
    return (
        <Table
            headers={[
                'Fecha',
                'Proveedor',
                'Tipo',
                'Referencia',
                'Monto',
                'Aplicado',
                'Sin aplicar',
                'Cuenta',
                'Estado',
                'Origen',
            ]}
            empty={payments.length === 0 && empty}
        >
            {payments.map((payment) => (
                <tr key={payment.key}>
                    <td className="px-2 py-2">{formatDate(payment.date)}</td>
                    <td className="px-2 py-2 font-bold">
                        {payment.supplier}
                        <p className="text-[11px] font-normal text-[#526b92]">
                            {payment.company}
                        </p>
                    </td>
                    <td className="px-2 py-2">{payment.kind_label}</td>
                    <td className="px-2 py-2">
                        {payment.reference}
                        {payment.proposal && (
                            <p className="text-[11px] text-[#526b92]">
                                Propuesta {payment.proposal}
                            </p>
                        )}
                    </td>
                    <td className="px-2 py-2 font-semibold">
                        {amount(payment.amount_cents)}
                    </td>
                    <td className="px-2 py-2">
                        {payment.applied_cents === null
                            ? '—'
                            : amount(payment.applied_cents)}
                    </td>
                    <td
                        className={`px-2 py-2 ${(payment.unapplied_cents ?? 0) > 0 ? 'font-semibold text-amber-700' : ''}`}
                    >
                        {payment.unapplied_cents === null
                            ? '—'
                            : amount(payment.unapplied_cents)}
                    </td>
                    <td className="px-2 py-2">{payment.account}</td>
                    <td className="px-2 py-2">
                        <span
                            className={toneBadge(
                                paymentTone[payment.status] ?? 'slate',
                            )}
                            title={payment.approvers.join(', ') || undefined}
                        >
                            {payment.status_label}
                        </span>
                    </td>
                    <td className="px-2 py-2">
                        <a
                            href={payment.destination}
                            className="text-xs font-bold text-[#640000]"
                        >
                            {payment.source}
                        </a>
                    </td>
                </tr>
            ))}
        </Table>
    );
}

/** Supplier advances and unapplied balances with their applications. */
export function AdvancesTable({
    advances,
    empty,
    onApply,
    canApply = false,
}: {
    advances: Advance[];
    empty: string;
    onApply?: (advance: Advance) => void;
    canApply?: boolean;
}) {
    return (
        <Table
            headers={[
                'Fecha',
                'Proveedor',
                'Origen',
                'Referencia',
                'Monto',
                'Aplicado',
                'Saldo',
                'Antigüedad',
                'Estado',
                '',
            ]}
            empty={advances.length === 0 && empty}
        >
            {advances.map((advance) => (
                <tr key={advance.id} className="align-top">
                    <td className="px-2 py-2">{formatDate(advance.paid_on)}</td>
                    <td className="px-2 py-2 font-bold">
                        {advance.supplier}
                        <p className="text-[11px] font-normal text-[#526b92]">
                            {advance.company}
                        </p>
                    </td>
                    <td className="px-2 py-2">{advance.origin_label}</td>
                    <td className="px-2 py-2">
                        {advance.reference}
                        {advance.applications.map((application) => (
                            <p
                                key={application.id}
                                className={`text-[11px] ${application.reversed ? 'text-slate-400 line-through' : 'text-[#526b92]'}`}
                            >
                                → {application.document} ·{' '}
                                {amount(application.amount_cents)}
                                {canApply && !application.reversed && (
                                    <button
                                        type="button"
                                        className="ml-1 font-bold text-[#640000]"
                                        onClick={() => {
                                            const reason = window.prompt(
                                                'Motivo de la reversión',
                                            );

                                            if (reason) {
                                                router.post(
                                                    `/payables/advance-applications/${application.id}/reverse`,
                                                    { reason },
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        revertir
                                    </button>
                                )}
                            </p>
                        ))}
                    </td>
                    <td className="px-2 py-2">
                        {amount(advance.amount_cents)}
                    </td>
                    <td className="px-2 py-2">
                        {amount(advance.applied_cents)}
                    </td>
                    <td className="px-2 py-2 font-semibold text-amber-700">
                        {amount(advance.outstanding_cents)}
                    </td>
                    <td className="px-2 py-2">{advance.age_days} día(s)</td>
                    <td className="px-2 py-2">
                        <span
                            className={toneBadge(
                                advanceTone[advance.status] ?? 'slate',
                            )}
                        >
                            {advance.status_label}
                        </span>
                    </td>
                    <td className="px-2 py-2">
                        {canApply &&
                            onApply &&
                            advance.outstanding_cents > 0 && (
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    onClick={() => onApply(advance)}
                                >
                                    Aplicar
                                </button>
                            )}
                    </td>
                </tr>
            ))}
        </Table>
    );
}
