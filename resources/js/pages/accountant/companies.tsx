import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    CompaniesTable,
    statusOptions,
    useFilterNavigation,
} from '@/components/accountant/kit';
import type {
    AccountantCompany,
    AccountantContext,
} from '@/components/accountant/kit';
import {
    Notice,
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card } from '@/components/supervisor/kit';

export default function AccountantCompanies({
    context,
    companies,
    filters,
    notice,
}: {
    context: AccountantContext;
    companies: AccountantCompany[];
    filters: Record<string, string>;
    notice: string | null;
}) {
    const apply = useFilterNavigation('/accountant/companies');
    const [search, setSearch] = useState(filters.search ?? '');

    return (
        <>
            <Head title="Empresas a cargo" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Empresas a cargo"
                    subtitle={`Empresas donde eres el contador encargado · ${context.period?.name ?? 'Sin período'}`}
                />
                <Card
                    title={`${companies.length} empresa(s)`}
                    action={
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                apply('search', search);
                            }}
                        >
                            <input
                                aria-label="Buscar"
                                className="h-9 rounded-lg border border-slate-200 px-3 text-sm"
                                placeholder="Empresa o NIT"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <select
                                aria-label="Estado"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    apply('status', event.target.value)
                                }
                            >
                                <option value="">Todos los estados</option>
                                {statusOptions.map(([code, label]) => (
                                    <option key={code} value={code}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Auxiliar"
                                className="h-9 rounded-lg border border-slate-200 px-2 text-sm"
                                value={filters.assistant_id ?? ''}
                                onChange={(event) =>
                                    apply('assistant_id', event.target.value)
                                }
                            >
                                <option value="">Todos los auxiliares</option>
                                {context.assistants.map((assistant) => (
                                    <option
                                        key={assistant.id}
                                        value={assistant.id}
                                    >
                                        {assistant.name}
                                    </option>
                                ))}
                            </select>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    apply(
                                        'with_observations',
                                        filters.with_observations ? '' : '1',
                                    )
                                }
                            >
                                {filters.with_observations
                                    ? 'Todas'
                                    : 'Solo con observaciones'}
                            </button>
                        </form>
                    }
                >
                    <CompaniesTable
                        companies={companies}
                        empty="No tienes empresas a cargo con esos filtros."
                    />
                </Card>
            </Shell>
        </>
    );
}
