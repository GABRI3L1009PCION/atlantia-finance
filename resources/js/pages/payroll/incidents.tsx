import { Link, usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { usePayrollHref } from '@/components/payroll/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';
import AssistantIncidents from '@/pages/assistant/incidents';

type Alert = {
    severity: string;
    title: string;
    company: string;
    period: string | null;
    kind: string;
    destination: string;
};

const kindLabel: Record<string, string> = {
    calculation: 'Validación',
    recalculation: 'Recálculo',
    incident: 'Incidencia',
};

/** Payroll incidents (errors, failed payments, ledger differences, duplicated novelties) plus live validation alerts. */
export default function PayrollIncidents(
    props: ComponentProps<typeof AssistantIncidents> & { alerts: Alert[] },
) {
    const userId = (usePage().props.auth as { user: { id: number } }).user.id;
    const href = usePayrollHref();
    const alerts = props.alerts.filter((alert) => alert.kind !== 'incident');

    return (
        <>
            <AssistantIncidents
                {...props}
                basePath="/payroll"
                userId={userId}
            />
            <div className="bg-[#f6f8fb] px-2 pb-4 sm:px-4">
                <Card title={`Alertas de validación (${alerts.length})`}>
                    <Table
                        headers={['Tipo', 'Alerta', 'Empresa', '']}
                        empty={
                            alerts.length === 0 &&
                            'Las nóminas en curso no tienen alertas de validación.'
                        }
                    >
                        {alerts.map((alert, index) => (
                            <tr key={index}>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            alert.severity === 'high'
                                                ? 'red'
                                                : 'amber',
                                        )}
                                    >
                                        {kindLabel[alert.kind] ?? alert.kind}
                                    </span>
                                </td>
                                <td className="px-2 py-2 font-semibold">
                                    {alert.title}
                                </td>
                                <td className="px-2 py-2">
                                    {alert.company}
                                    {alert.period && (
                                        <p className="text-[11px] text-[#526b92]">
                                            {alert.period}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <Link
                                        href={href(alert.destination)}
                                        className="text-xs font-bold text-[#640000]"
                                    >
                                        Revisar
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>
        </>
    );
}
