import { Head } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import { useAccountantHref } from '@/components/accountant/kit';
import type {
    AccountantContext,
    AccountantPermissions,
} from '@/components/accountant/kit';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

const reports: [string, string, string][] = [
    [
        'companies',
        'Empresas a cargo',
        'Estado, avance, auxiliar y observaciones por empresa.',
    ],
    [
        'observations',
        'Observaciones de revisión',
        'Todas las observaciones con responsable y estado.',
    ],
    [
        'entries',
        'Pólizas pendientes',
        'Pólizas por revisar, aprobar o contabilizar.',
    ],
    [
        'reconciliations',
        'Conciliaciones',
        'CxC, CxP y bancos contra el mayor, con diferencias.',
    ],
    ['closings', 'Cierres', 'Requisitos de cierre y solicitudes por empresa.'],
];

export default function AccountantReports({
    context,
    permissions,
}: {
    context: AccountantContext;
    permissions: AccountantPermissions;
}) {
    const href = useAccountantHref();

    return (
        <>
            <Head title="Reportes" />
            <Shell>
                <PageHeader
                    title="Reportes"
                    subtitle={`Excel o PDF · ${context.period?.name ?? 'Sin período'} · solo tus empresas a cargo`}
                />
                {!permissions.canExport && (
                    <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        Tu rol no tiene permiso para exportar reportes.
                    </p>
                )}
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
                                {permissions.canExport && (
                                    <div className="flex shrink-0 gap-1">
                                        <a
                                            href={href(
                                                `/accountant/reports/${key}?format=xlsx`,
                                            )}
                                            className={secondaryButton}
                                        >
                                            <FileSpreadsheet className="size-3.5 text-emerald-700" />{' '}
                                            Excel
                                        </a>
                                        <a
                                            href={href(
                                                `/accountant/reports/${key}?format=pdf`,
                                            )}
                                            className={secondaryButton}
                                        >
                                            <FileText className="size-3.5 text-red-700" />{' '}
                                            PDF
                                        </a>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            </Shell>
        </>
    );
}
