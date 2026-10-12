import { Head } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, useSupervisorHref } from '@/components/supervisor/kit';
import type { SupervisorContext } from '@/components/supervisor/kit';

const reports: [string, string, string][] = [
    [
        'companies',
        'Empresas supervisadas',
        'Avance contable, estado fiscal y documentos por empresa.',
    ],
    [
        'periods',
        'Estado de períodos',
        'Etapa, avance y atraso del período de cada empresa.',
    ],
    [
        'workload',
        'Carga y productividad del equipo',
        'Tareas abiertas, vencidas, en revisión y completadas.',
    ],
    [
        'tasks',
        'Tareas pendientes',
        'Trabajo abierto con responsable y vencimiento.',
    ],
    [
        'reviews',
        'Revisiones',
        'Revisiones contables y fiscales con observaciones.',
    ],
    [
        'incidents',
        'Incidencias',
        'Incidencias por empresa, severidad y estado.',
    ],
    [
        'obligations',
        'Vencimientos fiscales',
        'Obligaciones IVA e ISR y su estado.',
    ],
    [
        'accounting',
        'Contabilidad por empresa',
        'Pólizas en borrador, revisión, aprobadas y contabilizadas.',
    ],
    [
        'receivables',
        'Cuentas por cobrar',
        'Saldos abiertos y vencidos de clientes.',
    ],
    ['payables', 'Cuentas por pagar', 'Facturas de proveedores pendientes.'],
    [
        'banks',
        'Bancos y conciliaciones',
        'Saldo banco contra saldo contable y pendientes.',
    ],
];

export default function SupervisorReports({
    context,
}: {
    context: SupervisorContext;
}) {
    const href = useSupervisorHref();

    return (
        <>
            <Head title="Reportes" />
            <Shell>
                <PageHeader
                    title="Reportes"
                    subtitle={`Descarga en Excel o PDF · ${context.period?.name ?? 'Sin período'} · solo tus empresas supervisadas`}
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
                                            `/supervisor/reports/${key}?format=xlsx`,
                                        )}
                                        className={secondaryButton}
                                    >
                                        <FileSpreadsheet className="size-3.5 text-emerald-700" />{' '}
                                        Excel
                                    </a>
                                    <a
                                        href={href(
                                            `/supervisor/reports/${key}?format=pdf`,
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
