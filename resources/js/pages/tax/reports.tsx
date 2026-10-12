import { Head } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';
import { useTaxHref } from '@/components/tax/kit';
import type { TaxContext } from '@/components/tax/kit';

const reports: [string, string, string][] = [
    [
        'status',
        'Estado fiscal de mis empresas',
        'Obligaciones, libros, FEL, documentos y avance por empresa.',
    ],
    [
        'obligations',
        'Obligaciones pendientes',
        'Obligaciones abiertas con límites interno y oficial.',
    ],
    [
        'overdue',
        'Obligaciones vencidas',
        'Obligaciones que superaron su vencimiento oficial.',
    ],
    ['fel', 'Incidencias FEL', 'Documentos con error o en certificación.'],
    [
        'documents',
        'Documentos faltantes',
        'Solicitudes sin recibir y compras sin soporte.',
    ],
    [
        'reconciliation',
        'Conciliación fiscal-contable',
        'IVA según libros contra el mayor.',
    ],
];

export default function TaxReports({ context }: { context: TaxContext }) {
    const href = useTaxHref();

    return (
        <>
            <Head title="Reportes fiscales" />
            <Shell>
                <PageHeader
                    title="Reportes fiscales"
                    subtitle={`Reportes internos de Atlantia (no son constancias de presentación) · ${context.period?.name ?? 'Sin período'}`}
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
                                            `/tax/reports/${key}?format=xlsx`,
                                        )}
                                        className={secondaryButton}
                                    >
                                        <FileSpreadsheet className="size-3.5 text-emerald-700" />{' '}
                                        Excel
                                    </a>
                                    <a
                                        href={href(
                                            `/tax/reports/${key}?format=pdf`,
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
