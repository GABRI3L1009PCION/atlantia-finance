import { Head } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import { useAssistantHref } from '@/components/assistant/kit';
import type { AssistantContext } from '@/components/assistant/kit';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

const reports: [string, string, string][] = [
    [
        'companies',
        'Mis empresas',
        'Avance, pendientes, observaciones y estado por empresa.',
    ],
    [
        'tasks',
        'Mis tareas',
        'Tareas abiertas con prioridad, vencimiento y revisor.',
    ],
    ['pending', 'Pendientes', 'Todo lo pendiente ordenado por prioridad.'],
    ['documents', 'Documentos', 'Documentos solicitados o sin procesar.'],
    [
        'reconciliations',
        'Conciliaciones',
        'Bancos y auxiliares con diferencias.',
    ],
    ['observations', 'Observaciones', 'Observaciones recibidas y su estado.'],
    ['activity', 'Actividad', 'Lo que registraste en el sistema.'],
];

export default function AssistantReports({
    context,
}: {
    context: AssistantContext;
}) {
    const href = useAssistantHref();

    return (
        <>
            <Head title="Reportes" />
            <Shell>
                <PageHeader
                    title="Reportes"
                    subtitle={`Excel o PDF · ${context.period?.name ?? 'Sin período'} · solo tus empresas y tu trabajo`}
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
                                            `/assistant/reports/${key}?format=xlsx`,
                                        )}
                                        className={secondaryButton}
                                    >
                                        <FileSpreadsheet className="size-3.5 text-emerald-700" />{' '}
                                        Excel
                                    </a>
                                    <a
                                        href={href(
                                            `/assistant/reports/${key}?format=pdf`,
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
