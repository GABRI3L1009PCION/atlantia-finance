import { Head, usePage } from '@inertiajs/react';
import { Download, FileSpreadsheet, FileText } from 'lucide-react';
import { PageHeader, Shell, panel } from '@/components/office-admin/kit';
import type { Context } from '@/components/operations/kit';

const reports = [
    {
        key: 'companies',
        title: 'Empresas por estado',
        description:
            'Estado, avance, pendientes, atrasos y bloqueos por empresa.',
    },
    {
        key: 'overdue_tasks',
        title: 'Tareas vencidas',
        description: 'Tareas abiertas con fecha vencida y días de atraso.',
    },
    {
        key: 'workload',
        title: 'Carga del equipo',
        description:
            'Empresas, tareas, vencidas, revisiones y utilización por colaborador.',
    },
    {
        key: 'reviews',
        title: 'Revisiones',
        description: 'Cola de revisión con tiempo de espera y observaciones.',
    },
    {
        key: 'closings',
        title: 'Cierres',
        description:
            'Avance, estado de revisión y bloqueos de cierre por empresa.',
    },
    {
        key: 'incidents',
        title: 'Incidencias',
        description: 'Incidencias por tipo, severidad, estado y responsable.',
    },
];

export default function OperationsReports({ context }: { context: Context }) {
    const page = usePage();
    const params = new URL(page.url, 'https://atlantia.local').searchParams;
    const query = (format: string) => {
        const search = new URLSearchParams({ format });
        ['office_id', 'period'].forEach((key) => {
            const value = params.get(key);

            if (value) {
                search.set(key, value);
            }
        });

        return search.toString();
    };

    return (
        <>
            <Head title="Reportes operativos" />
            <Shell>
                <PageHeader
                    title="Reportes operativos"
                    subtitle={`Descargas con la oficina y el período seleccionados · ${context.period?.name ?? 'Sin período'}`}
                />
                <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {reports.map((report) => (
                        <div
                            key={report.key}
                            className={`${panel} flex flex-col justify-between p-4`}
                        >
                            <div>
                                <p className="flex items-center gap-2 font-black">
                                    <Download className="size-4 text-[#9b5b20]" />
                                    {report.title}
                                </p>
                                <p className="mt-1 text-sm text-[#526b92]">
                                    {report.description}
                                </p>
                            </div>
                            <div className="mt-3 flex gap-2">
                                <a
                                    href={`/operations/reports/${report.key}?${query('xlsx')}`}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800"
                                >
                                    <FileSpreadsheet className="size-3.5" />{' '}
                                    Excel
                                </a>
                                <a
                                    href={`/operations/reports/${report.key}?${query('pdf')}`}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-800"
                                >
                                    <FileText className="size-3.5" /> PDF
                                </a>
                            </div>
                        </div>
                    ))}
                </section>
            </Shell>
        </>
    );
}
