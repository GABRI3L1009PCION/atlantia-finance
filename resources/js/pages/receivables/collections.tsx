import { Head, Link, router } from '@inertiajs/react';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
} from '@/components/office-admin/kit';
import {
    CollectionForm,
    activityTone,
    useReceivablesHref,
} from '@/components/receivables/kit';
import type {
    Activity,
    CollectionOptions,
    ReceivablesContext,
    ReceivablesPermissions,
} from '@/components/receivables/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

export default function ReceivablesCollections({
    activities,
    filters,
    customers,
    options,
    permissions,
    notice,
}: {
    context: ReceivablesContext;
    activities: Activity[];
    filters: { status?: string };
    customers: { id: number; name: string; company: string }[];
    options: CollectionOptions;
    permissions: ReceivablesPermissions;
    notice: string | null;
}) {
    const href = useReceivablesHref();
    const status = filters.status ?? '';

    return (
        <>
            <Head title="Gestiones de cobranza" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Gestiones de cobranza"
                    subtitle="Historial inmutable: una corrección es una nueva gestión; la próxima acción se convierte en tarea"
                />
                {permissions.canCollect && customers.length > 0 && (
                    <Card title="Registrar gestión">
                        <CollectionForm
                            customers={customers}
                            options={options}
                        />
                    </Card>
                )}
                <Card title={`${activities.length} gestión(es)`}>
                    <div className="mb-3 flex gap-1.5">
                        {[
                            ['', 'Todas'],
                            ['open', 'Con seguimiento'],
                            ['overdue', 'Vencidas'],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    router.get(
                                        href('/receivables/collections'),
                                        key ? { status: key } : {},
                                    )
                                }
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${status === key ? 'border-[#061633] text-[#061633]' : 'border-slate-200 text-[#526b92]'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <Table
                        headers={[
                            'Fecha',
                            'Cliente',
                            'Documento',
                            'Canal',
                            'Resultado',
                            'Notas',
                            'Próxima acción',
                            'Responsable',
                            'Estado',
                        ]}
                        empty={
                            activities.length === 0 &&
                            'Aún no hay gestiones registradas.'
                        }
                    >
                        {activities.map((activity) => (
                            <tr key={activity.id}>
                                <td className="px-2 py-2">
                                    {formatDate(activity.performed_at, true)}
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    <Link
                                        href={href(activity.destination)}
                                        className="hover:text-[#640000]"
                                    >
                                        {activity.customer}
                                    </Link>
                                    <p className="text-[11px] font-normal text-[#526b92]">
                                        {activity.company}
                                    </p>
                                </td>
                                <td className="px-2 py-2">
                                    {activity.document ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {activity.channel_label}
                                </td>
                                <td className="px-2 py-2">
                                    {activity.result_label}
                                </td>
                                <td className="max-w-48 px-2 py-2">
                                    {activity.notes ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {activity.next_action
                                        ? `${activity.next_action} · ${formatDate(activity.next_action_at, true)}`
                                        : '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {activity.performer}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            activityTone[activity.status] ??
                                                'slate',
                                        )}
                                    >
                                        {activity.status_label}
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
