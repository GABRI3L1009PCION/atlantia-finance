import { Head } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';
import { useTreasuryHref } from '@/components/treasury/kit';
import type { TreasuryContext } from '@/components/treasury/kit';

const reports: [string, string, string][] = [
    [
        'balances',
        'Saldos bancarios por cuenta',
        'Saldo bancario, saldo contable y diferencia de cada cuenta.',
    ],
    [
        'reconciliations',
        'Conciliaciones bancarias',
        'Cortes, diferencias, estado y vigencia de cada conciliación.',
    ],
    [
        'movements',
        'Movimientos pendientes',
        'Movimientos del banco por conciliar o sin identificar.',
    ],
    [
        'payments',
        'Pagos',
        'Pagos preparados, aprobaciones, ejecución y referencias.',
    ],
    ['receipts', 'Cobros', 'Cobros recibidos, aplicados y saldos sin aplicar.'],
    [
        'cash-flow',
        'Flujo de caja',
        'Real contra proyectado del período, por empresa.',
    ],
];

export default function TreasuryReports({
    context,
}: {
    context: TreasuryContext;
}) {
    const href = useTreasuryHref();

    return (
        <>
            <Head title="Reportes de tesorería" />
            <Shell>
                <PageHeader
                    title="Reportes de tesorería"
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
                                            `/treasury/reports/${key}?format=xlsx`,
                                        )}
                                        className={secondaryButton}
                                    >
                                        <FileSpreadsheet className="size-3.5 text-emerald-700" />{' '}
                                        Excel
                                    </a>
                                    <a
                                        href={href(
                                            `/treasury/reports/${key}?format=pdf`,
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
