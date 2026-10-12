import { Head } from '@inertiajs/react';
import {
    PageHeader,
    SectionHeader,
    Shell,
    panel,
} from '@/components/office-admin/kit';
import type { Context } from '@/components/operations/kit';

type Group = { name: string; companies: number; progress: number };
type Progress = {
    companies: {
        id: number;
        name: string;
        status_label: string;
        progress: number;
        stages: {
            label: string;
            progress: number;
            total: number;
            done: number;
        }[];
    }[];
    offices: Group[];
    responsibles: Group[];
    stages: { label: string; total: number; done: number; progress: number }[];
};

function Bar({ value }: { value: number }) {
    return (
        <div className="h-2.5 w-full overflow-hidden rounded bg-slate-100">
            <div
                className={`h-full ${value >= 100 ? 'bg-emerald-500' : value >= 60 ? 'bg-amber-400' : 'bg-blue-500'}`}
                style={{ width: `${value}%` }}
            />
        </div>
    );
}

export default function OperationsProgress({
    context,
    progress,
}: {
    context: Context;
    progress: Progress;
}) {
    const groups: [string, Group[]][] = [
        ['Por oficina', progress.offices],
        ['Por contador responsable', progress.responsibles],
    ];

    return (
        <>
            <Head title="Avance" />
            <Shell>
                <PageHeader
                    title="Avance"
                    subtitle={`Tareas terminadas sobre tareas del período · ${context.period?.name ?? 'Sin período'}`}
                />
                <section className="grid gap-3 xl:grid-cols-3">
                    <div className={`${panel} p-3`}>
                        <SectionHeader title="Por etapa" />
                        {progress.stages.map((stage) => (
                            <div
                                key={stage.label}
                                className="mb-2 grid grid-cols-[120px_1fr_70px] items-center gap-2 text-xs"
                            >
                                <span>{stage.label}</span>
                                <Bar value={stage.progress} />
                                <span className="text-right font-black">
                                    {stage.progress}%{' '}
                                    <span className="font-normal text-[#526b92]">
                                        ({stage.done}/{stage.total})
                                    </span>
                                </span>
                            </div>
                        ))}
                        {progress.stages.length === 0 && (
                            <p className="text-sm text-slate-500">
                                Aún no hay tareas en el período.
                            </p>
                        )}
                    </div>
                    {groups.map(([title, rows]) => (
                        <div key={title} className={`${panel} p-3`}>
                            <SectionHeader title={title} />
                            {rows.map((row) => (
                                <div
                                    key={row.name}
                                    className="mb-2 grid grid-cols-[140px_1fr_44px] items-center gap-2 text-xs"
                                >
                                    <span className="truncate">
                                        {row.name}{' '}
                                        <span className="text-[#526b92]">
                                            ({row.companies})
                                        </span>
                                    </span>
                                    <Bar value={row.progress} />
                                    <span className="text-right font-black">
                                        {row.progress}%
                                    </span>
                                </div>
                            ))}
                        </div>
                    ))}
                </section>
                <section className={`${panel} p-3`}>
                    <SectionHeader title="Por empresa y etapa" />
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead className="bg-[#f3f6f9] text-left text-[#526b92]">
                                <tr>
                                    <th className="px-2 py-2">Empresa</th>
                                    <th className="px-2 py-2">Estado</th>
                                    <th className="w-40 px-2 py-2">Avance</th>
                                    <th className="px-2 py-2">Etapas</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {progress.companies.map((company) => (
                                    <tr key={company.id}>
                                        <td className="px-2 py-2 font-bold">
                                            {company.name}
                                        </td>
                                        <td className="px-2 py-2">
                                            {company.status_label}
                                        </td>
                                        <td className="px-2 py-2">
                                            <div className="flex items-center gap-2">
                                                <Bar value={company.progress} />
                                                <span className="font-black">
                                                    {company.progress}%
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-2 py-2">
                                            {company.stages.map((stage) => (
                                                <span
                                                    key={stage.label}
                                                    className="mr-2 inline-block"
                                                >
                                                    {stage.label}{' '}
                                                    <b>{stage.progress}%</b>
                                                </span>
                                            ))}
                                            {company.stages.length === 0 && (
                                                <span className="text-[#526b92]">
                                                    Sin tareas
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </Shell>
        </>
    );
}
