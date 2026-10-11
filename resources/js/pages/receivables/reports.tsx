import { Head } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { useReceivablesHref } from '@/components/receivables/kit';
import type { ReceivablesContext } from '@/components/receivables/kit';
import { Card } from '@/components/supervisor/kit';

const reports: [string, string, string][] = [
    [
        'companies',
        'Cartera por empresa',
        'Por vencer, vencido y estado de cada empresa.',
    ],
    [
        'customers',
        'Cartera por cliente',
        'Saldos y prioridad de cobranza por cliente.',
    ],
    [
        'aging',
        'Antigüedad de saldos',
        'Tramos de antigüedad sobre el saldo pendiente.',
    ],
    ['overdue', 'Documentos vencidos', 'Documentos con saldo y días vencidos.'],
    [
        'upcoming',
        'Documentos por vencer',
        'Vencimientos de los próximos 30 días.',
    ],
    ['receipts', 'Cobros', 'Cobros recibidos y su aplicación.'],
    [
        'unapplied',
        'Cobros sin aplicar',
        'Saldos de cobros pendientes de aplicar.',
    ],
    ['promises', 'Promesas', 'Promesas de pago y su cumplimiento.'],
    [
        'broken-promises',
        'Promesas incumplidas',
        'Promesas vencidas sin pago suficiente.',
    ],
    ['collections', 'Gestiones', 'Historial de gestiones de cobranza.'],
    ['disputes', 'Disputas', 'Documentos en disputa y su resolución.'],
    [
        'credit-notes',
        'Notas de crédito',
        'Notas de crédito emitidas por Ventas.',
    ],
    [
        'reconciliation',
        'Conciliación CxC - Mayor',
        'Auxiliar contra la cuenta de control por empresa.',
    ],
];

export default function ReceivablesReports({
    context,
}: {
    context: ReceivablesContext;
}) {
    const href = useReceivablesHref();

    return (
        <>
            <Head title="Reportes de cuentas por cobrar" />
            <Shell>
                <PageHeader
                    title="Reportes de cuentas por cobrar"
                    subtitle={`Reportes internos de Atlantia; los montos se presentan por empresa, sin consolidar · ${context.period?.name ?? 'Sin período'}`}
                />
                <Card title="Reportes disponibles">
                    <ul className="grid gap-2 md:grid-cols-2">
                        {reports.map(([key, title, description]) => (
                            <li
                                key={key}
                                className="flex items-center justify-between gap-3 rounded-xl border border-[#e7e1dd] p-3"
                            >
                                <div>
                                    <p className="font-bold text-[#061633]">
                                        {title}
                                    </p>
                                    <p className="text-xs text-[#526b92]">
                                        {description}
                                    </p>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <a
                                        href={href(
                                            `/receivables/reports/${key}?format=xlsx`,
                                        )}
                                        className={secondaryButton}
                                    >
                                        <FileSpreadsheet className="size-3.5 text-emerald-700" />{' '}
                                        Excel
                                    </a>
                                    <a
                                        href={href(
                                            `/receivables/reports/${key}?format=pdf`,
                                        )}
                                        className={secondaryButton}
                                    >
                                        <FileText className="size-3.5 text-red-700" />{' '}
                                        PDF
                                    </a>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            </Shell>
        </>
    );
}
