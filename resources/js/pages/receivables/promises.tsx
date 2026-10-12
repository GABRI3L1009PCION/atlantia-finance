import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
} from '@/components/office-admin/kit';
import {
    amount,
    promiseTone,
    useReceivablesHref,
} from '@/components/receivables/kit';
import type {
    PromiseRow,
    ReceivablesContext,
    ReceivablesPermissions,
} from '@/components/receivables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function ReceivablesPromises({
    promises,
    filters,
    statuses,
    customers,
    permissions,
    notice,
}: {
    context: ReceivablesContext;
    promises: PromiseRow[];
    filters: { status?: string };
    statuses: Record<string, string>;
    customers: { id: number; name: string; company: string }[];
    permissions: ReceivablesPermissions;
    notice: string | null;
}) {
    const href = useReceivablesHref();
    const status = filters.status ?? 'active';
    const form = useForm({
        customer_id: customers[0] ? String(customers[0].id) : '',
        amount: '',
        promised_date: '',
        notes: '',
    });

    return (
        <>
            <Head title="Promesas de pago" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Promesas de pago"
                    subtitle="Una promesa no reduce el saldo: se cumple con los cobros aplicados y, si vence sin pago, genera seguimiento"
                />
                {permissions.canPromise && customers.length > 0 && (
                    <Card title="Registrar promesa">
                        <form
                            className="grid gap-2 md:grid-cols-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/receivables/promises', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.reset('amount', 'notes'),
                                });
                            }}
                        >
                            <select
                                aria-label="Cliente"
                                className={input}
                                value={form.data.customer_id}
                                onChange={(event) =>
                                    form.setData(
                                        'customer_id',
                                        event.target.value,
                                    )
                                }
                            >
                                {customers.map((customer) => (
                                    <option
                                        key={customer.id}
                                        value={customer.id}
                                    >
                                        {customer.company} · {customer.name}
                                    </option>
                                ))}
                            </select>
                            <input
                                aria-label="Monto"
                                className={input}
                                inputMode="decimal"
                                placeholder="Monto (Q)"
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                            <input
                                aria-label="Fecha prometida"
                                type="date"
                                className={input}
                                value={form.data.promised_date}
                                onChange={(event) =>
                                    form.setData(
                                        'promised_date',
                                        event.target.value,
                                    )
                                }
                            />
                            <input
                                aria-label="Notas"
                                className={input}
                                placeholder="Notas"
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={form.processing}
                            >
                                Registrar
                            </button>
                            {Object.values(form.errors).length > 0 && (
                                <p className="text-xs text-red-700 md:col-span-5">
                                    {Object.values(form.errors).join(' ')}
                                </p>
                            )}
                        </form>
                    </Card>
                )}
                <Card title={`${promises.length} promesa(s)`}>
                    <div className="mb-3 flex flex-wrap gap-1.5">
                        {[
                            ['active', 'Vigentes'],
                            ...Object.entries(statuses),
                            ['all', 'Todas'],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    router.get(href('/receivables/promises'), {
                                        status: key,
                                    })
                                }
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${status === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <Table
                        headers={[
                            'Cliente',
                            'Empresa',
                            'Monto',
                            'Fecha prometida',
                            'Cumplido',
                            'Documentos',
                            'Registró',
                            'Estado',
                        ]}
                        empty={
                            promises.length === 0 &&
                            'No existen promesas de pago activas.'
                        }
                    >
                        {promises.map((promise) => (
                            <tr key={promise.id}>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(promise.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {promise.customer}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">{promise.company}</td>
                                <td className="px-2 py-2">
                                    {amount(promise.amount_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(promise.promised_date)}
                                </td>
                                <td className="px-2 py-2">
                                    {amount(promise.fulfilled_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {promise.documents.join(', ') ||
                                        'Saldo general'}
                                </td>
                                <td className="px-2 py-2">
                                    {promise.created_by}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            promiseTone[promise.status] ??
                                                'slate',
                                        )}
                                    >
                                        {promise.status_label}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
