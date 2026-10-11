import { Head } from '@inertiajs/react';
import { Building2, ChevronRight } from 'lucide-react';
import type { AssistantContext } from '@/components/assistant/kit';
import { PageHeader, Shell } from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

export default function AssistantModule({
    module,
    companies,
}: {
    context: AssistantContext;
    module: { key: string; label: string };
    companies: { id: number; name: string; tax_id: string; href: string }[];
}) {
    return (
        <>
            <Head title={module.label} />
            <Shell>
                <PageHeader
                    title={module.label}
                    subtitle="Elige la empresa en la que vas a trabajar"
                />
                <Card title={`${companies.length} empresa(s) asignada(s)`}>
                    {companies.length === 0 ? (
                        <p className="py-6 text-center text-sm text-slate-500">
                            No tienes empresas asignadas.
                        </p>
                    ) : (
                        <ul className="grid gap-2 md:grid-cols-2">
                            {companies.map((company) => (
                                <li key={company.id}>
                                    <a
                                        href={company.href}
                                        className="flex items-center gap-3 rounded-xl border border-[#e7e1dd] p-3 hover:border-[#640000]/40"
                                    >
                                        <span className="grid size-10 place-items-center rounded-lg bg-blue-50 text-blue-700">
                                            <Building2 className="size-5" />
                                        </span>
                                        <span className="flex-1">
                                            <b className="block">
                                                {company.name}
                                            </b>
                                            <span className="text-xs text-[#526b92]">
                                                NIT {company.tax_id}
                                            </span>
                                        </span>
                                        <ChevronRight className="size-4 text-slate-400" />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </Shell>
        </>
    );
}
