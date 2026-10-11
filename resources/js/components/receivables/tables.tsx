import { Link } from '@inertiajs/react';
import { formatDate } from '@/components/office-admin/kit';
import {
    amount,
    documentTone,
    dueLabel,
    priorityTone,
    useReceivablesHref,
} from '@/components/receivables/kit';
import type { CustomerBalance, Receivable } from '@/components/receivables/kit';
import { Table, toneBadge } from '@/components/supervisor/kit';

/** Customer portfolio table shared by the customers, company and overdue screens. */
export function CustomersTable({
    customers,
    empty,
}: {
    customers: CustomerBalance[];
    empty: string;
}) {
    const href = useReceivablesHref();

    return (
        <Table
            headers={[
                'Cliente',
                'Saldo total',
                'Vencido',
                'Por vencer',
                'Doc. más antiguo',
                'Máx. días',
                'Última gestión',
                'Próxima gestión',
                'Promesa activa',
                'Prioridad',
            ]}
            empty={customers.length === 0 && empty}
        >
            {customers.map((customer) => {
                const [priority, tone] = priorityTone[customer.priority] ?? [
                    customer.priority,
                    'slate',
                ];

                return (
                    <tr key={customer.id}>
                        <td className="px-2 py-2 font-bold">
                            <Link
                                href={href(customer.destination)}
                                className="hover:text-[#640000]"
                            >
                                {customer.name}
                            </Link>
                            <p className="text-[11px] font-normal text-[#526b92]">
                                {customer.company} · NIT{' '}
                                {customer.tax_id ?? 'No indicado'}
                            </p>
                        </td>
                        <td className="px-2 py-2 font-semibold">
                            {amount(customer.total_cents)}
                        </td>
                        <td className="px-2 py-2 text-red-700">
                            {amount(customer.overdue_cents)}
                        </td>
                        <td className="px-2 py-2">
                            {amount(customer.current_cents)}
                        </td>
                        <td className="px-2 py-2">
                            {formatDate(customer.oldest_due_date)}
                        </td>
                        <td className="px-2 py-2">
                            {customer.max_overdue_days}
                        </td>
                        <td className="px-2 py-2">
                            {customer.last_activity
                                ? `${formatDate(customer.last_activity.at)} · ${customer.last_activity.result}`
                                : 'Sin gestión'}
                        </td>
                        <td
                            className={`px-2 py-2 ${customer.next_action?.overdue ? 'font-bold text-red-700' : ''}`}
                        >
                            {customer.next_action
                                ? `${formatDate(customer.next_action.at)} · ${customer.next_action.action ?? ''}`
                                : '—'}
                        </td>
                        <td className="px-2 py-2">
                            {customer.active_promise
                                ? `${amount(customer.active_promise.amount_cents)} · ${formatDate(customer.active_promise.promised_date)}`
                                : customer.broken_promise
                                  ? 'Incumplida'
                                  : '—'}
                        </td>
                        <td className="px-2 py-2">
                            <span
                                className={toneBadge(tone)}
                                title={customer.priority_reasons.join(' · ')}
                            >
                                {priority}
                            </span>
                        </td>
                    </tr>
                );
            })}
        </Table>
    );
}

/** Open documents table shared by several screens. */
export function DocumentsTable({
    documents,
    empty,
    showCustomer = true,
}: {
    documents: Receivable[];
    empty: string;
    showCustomer?: boolean;
}) {
    const href = useReceivablesHref();

    return (
        <Table
            headers={[
                'Documento',
                ...(showCustomer ? ['Cliente'] : []),
                'Emisión',
                'Vence',
                'Original',
                'Cobrado',
                'NC y ajustes',
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
                        </td>
                        {showCustomer && (
                            <td className="px-2 py-2">
                                {document.customer}
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
                            {amount(document.applied_cents)}
                        </td>
                        <td className="px-2 py-2">
                            {amount(
                                document.credited_cents +
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
