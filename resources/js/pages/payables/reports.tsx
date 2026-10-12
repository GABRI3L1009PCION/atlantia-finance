import { Head } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { usePayablesHref } from '@/components/payables/kit';
import type { PayablesContext } from '@/components/payables/kit';
import { Card } from '@/components/supervisor/kit';

const reports: [string, string, string][] = [
    [
        'companies',
        'CxP por empresa',
        'Por vencer, vencido y estado de cada empresa.',
    ],
    [
        'suppliers',
        'CxP por proveedor',
        'Saldos, vencidos y anticipos por proveedor.',
    ],
    [
        'aging',
        'Antigüedad de saldos',
        'Tramos de antigüedad sobre el saldo pendiente.',
    ],
    ['overdue', 'Facturas vencidas', 'Documentos con saldo y días de atraso.'],
    [
        'upcoming',
        'Próximos vencimientos',
        'Vencimientos de los próximos 30 días.',
    ],
    [
        'proposals',
        'Propuestas de pago',
        'Propuestas, su aprobación y su estado.',
    ],
    ['payments', 'Pagos realizados', 'Pagos a proveedores y su aplicación.'],
    [
        'unapplied',
        'Pagos sin aplicar',
        'Pagos ejecutados con monto sin aplicar.',
    ],
    ['advances', 'Anticipos', 'Anticipos a proveedores y sus aplicaciones.'],
    [
        'credit-notes',
        'Notas de crédito',
        'Notas de crédito de proveedores registradas en Compras.',
    ],
    ['disputes', 'Disputas', 'Documentos en disputa y su resolución.'],
    [
        'reconciliation',
        'Conciliación CxP - Mayor',
        'Auxiliar contra la cuenta de control por empresa.',
    ],
    ['incidents', 'Incidencias', 'Incidencias de cuentas por pagar.'],
];

export default function PayablesReports({
    context,
}: {
    context: PayablesContext;
}) {
    const href = usePayablesHref();

    return (
        <>
            <Head title="Reportes de cuentas por pagar" />
            <Shell>
                <PageHeader
                    title="Reportes de cuentas por pagar"
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
                                            `/payables/reports/${key}?format=xlsx`,
                                        )}
                                        className={secondaryButton}
                                    >
                                        <FileSpreadsheet className="size-3.5 text-emerald-700" />{' '}
                                        Excel
                                    </a>
                                    <a
                                        href={href(
                                            `/payables/reports/${key}?format=pdf`,
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
