import { useForm } from '@inertiajs/react';
import type { ClientPermissions } from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
} from '@/components/client-portal/kit';
import { input, primaryButton } from '@/components/office-admin/kit';

type Company = {
    id: number;
    name: string;
    trade_name: string | null;
    tax_id: string;
    address: string | null;
    phone: string | null;
    email: string | null;
    contact_name: string | null;
    legal_representative: string | null;
    office: string;
    accountant: string | null;
    accountant_email: string | null;
    period: string | null;
    status_label: string;
    pending_changes: {
        field: string;
        value: string;
        status: string;
        note: string | null;
        at: string;
    }[];
};

export default function ClientCompany({
    profile: company,
    fields,
    permissions,
    notice,
}: {
    profile: Company;
    fields: Record<string, string>;
    permissions: ClientPermissions;
    notice: string | null;
}) {
    const form = useForm({ field: 'email', value: '', reason: '' });
    const rows: [string, string | null][] = [
        ['Razón social', company.name],
        ['Nombre comercial', company.trade_name],
        ['NIT', company.tax_id],
        ['Dirección', company.address],
        ['Teléfono', company.phone],
        ['Correo', company.email],
        ['Contacto principal', company.contact_name],
        ['Representante legal', company.legal_representative],
    ];

    return (
        <ClientPage
            title="Mi empresa"
            subtitle="Datos generales registrados por tu oficina contable"
            notice={notice}
        >
            <div className="grid gap-3 xl:grid-cols-3">
                <Card title="Datos generales" className="xl:col-span-2">
                    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        {rows.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-xs text-[#526b92]">
                                    {label}
                                </dt>
                                <dd className="font-semibold text-[#061633]">
                                    {value || '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Card>
                <Card title="Tu oficina contable">
                    <dl className="grid gap-3 text-sm">
                        <div>
                            <dt className="text-xs text-[#526b92]">Oficina</dt>
                            <dd className="font-semibold">{company.office}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-[#526b92]">
                                Contador responsable
                            </dt>
                            <dd className="font-semibold">
                                {company.accountant ?? 'Por asignar'}
                            </dd>
                            {company.accountant_email && (
                                <dd className="text-xs text-[#526b92]">
                                    {company.accountant_email}
                                </dd>
                            )}
                        </div>
                        <div>
                            <dt className="text-xs text-[#526b92]">
                                Período actual
                            </dt>
                            <dd className="font-semibold">
                                {company.period ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-[#526b92]">Estado</dt>
                            <dd className="font-semibold">
                                {company.status_label}
                            </dd>
                        </div>
                    </dl>
                </Card>
            </div>

            {permissions['client.company.change_request'] && (
                <Card title="Solicitar un cambio de datos">
                    <form
                        className="grid gap-2 md:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/client/company/change-requests', {
                                preserveScroll: true,
                                onSuccess: () => form.reset('value', 'reason'),
                            });
                        }}
                    >
                        <select
                            aria-label="Dato"
                            className={input}
                            value={form.data.field}
                            onChange={(event) =>
                                form.setData('field', event.target.value)
                            }
                        >
                            {Object.entries(fields).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <input
                            className={input}
                            placeholder="Nuevo valor"
                            value={form.data.value}
                            onChange={(event) =>
                                form.setData('value', event.target.value)
                            }
                            required
                        />
                        <input
                            className={input}
                            placeholder="Motivo (opcional)"
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                        <button
                            type="submit"
                            className={primaryButton}
                            disabled={form.processing}
                        >
                            Enviar solicitud
                        </button>
                    </form>
                    <p className="mt-2 text-xs text-[#526b92]">
                        El cambio no se aplica de inmediato: tu oficina contable
                        lo valida primero. El NIT solo lo modifica la oficina.
                    </p>
                </Card>
            )}

            <Card title="Mis solicitudes de cambio">
                <Table
                    headers={[
                        'Dato',
                        'Valor solicitado',
                        'Estado',
                        'Nota',
                        'Fecha',
                    ]}
                    empty={
                        company.pending_changes.length === 0 &&
                        'No has solicitado cambios.'
                    }
                >
                    {company.pending_changes.map((change, index) => (
                        <tr key={index}>
                            <td className="px-2 py-2">{change.field}</td>
                            <td className="px-2 py-2 font-bold">
                                {change.value}
                            </td>
                            <td className="px-2 py-2">{change.status}</td>
                            <td className="px-2 py-2">{change.note ?? '—'}</td>
                            <td className="px-2 py-2">
                                {formatDate(change.at)}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </ClientPage>
    );
}
