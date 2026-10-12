import { Link } from '@inertiajs/react';
import type {
    ClientContext,
    ClientPermissions,
} from '@/components/client-portal/kit';
import {
    Card,
    ClientPage,
    Table,
    formatDate,
} from '@/components/client-portal/kit';
import { secondaryButton } from '@/components/office-admin/kit';

const permissionLabels: Record<string, string> = {
    'client.documents.upload': 'Subir documentos',
    'client.approvals.respond': 'Responder autorizaciones',
    'client.obligations.view': 'Consultar obligaciones',
    'client.reports.view': 'Consultar reportes',
    'client.financial_reports.view': 'Consultar estados financieros',
    'client.messages.send': 'Enviar mensajes',
    'client.fel.view': 'Consultar facturación FEL',
    'client.company.change_request': 'Solicitar cambios de datos',
};

export default function ClientPreferences({
    context,
    contact_role,
    reminders,
    permissions,
}: {
    context: ClientContext;
    contact_role: string;
    reminders: { title: string; remind_at: string; status: string }[];
    permissions: ClientPermissions;
}) {
    return (
        <ClientPage
            title="Preferencias"
            subtitle="Tu acceso al portal y tus recordatorios"
        >
            <div className="grid gap-3 lg:grid-cols-2">
                <Card title="Mi acceso">
                    <dl className="grid gap-3 text-sm">
                        <div>
                            <dt className="text-xs text-[#526b92]">Empresa</dt>
                            <dd className="font-semibold">
                                {context.company.name}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-[#526b92]">
                                Rol de contacto
                            </dt>
                            <dd className="font-semibold">{contact_role}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-[#526b92]">
                                Lo que puedes hacer
                            </dt>
                            <dd>
                                <ul className="mt-1 flex flex-wrap gap-1">
                                    {Object.entries(permissionLabels)
                                        .filter(([key]) => permissions[key])
                                        .map(([key, label]) => (
                                            <li
                                                key={key}
                                                className="rounded-full bg-slate-100 px-2 py-0.5 text-xs"
                                            >
                                                {label}
                                            </li>
                                        ))}
                                </ul>
                            </dd>
                        </div>
                    </dl>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Link
                            href="/settings/profile"
                            className={secondaryButton}
                        >
                            Perfil y contraseña
                        </Link>
                        <Link
                            href="/settings/security"
                            className={secondaryButton}
                        >
                            Seguridad
                        </Link>
                    </div>
                </Card>
                <Card title="Mis recordatorios">
                    <Table
                        headers={['Recordatorio', 'Fecha']}
                        empty={
                            reminders.length === 0 &&
                            'No tienes recordatorios programados.'
                        }
                    >
                        {reminders.map((reminder, index) => (
                            <tr key={index}>
                                <td className="px-2 py-2 font-bold">
                                    {reminder.title}
                                </td>
                                <td className="px-2 py-2">
                                    {formatDate(reminder.remind_at, true)}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>
        </ClientPage>
    );
}
