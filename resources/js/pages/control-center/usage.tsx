import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    Progress,
    Table,
    bytes,
    ccInput,
    statusTone,
} from '@/components/control-center/kit';

type Metric = {
    metric: string;
    label: string;
    value: number;
    limit: number | null;
    percent: number | null;
    status: string;
    enforcement: string;
};
type Row = {
    organization_id: number;
    organization: string;
    status: string;
    plan: string | null;
    metrics: Metric[];
    worst: string;
};

const statusLabel: Record<string, string> = {
    ok: 'Normal',
    warning: 'Advertencia',
    high: 'Alto',
    at_limit: 'En el límite',
    over_limit: 'OVER_LIMIT',
    unlimited: 'Ilimitado',
    not_included: 'No incluido',
};

export default function Usage({
    rows,
    filters,
    labels,
}: {
    rows: Row[];
    filters: { status?: string; search?: string };
    labels: Record<string, string>;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const metrics = Object.keys(labels);

    return (
        <>
            <Head title="Límites y consumo · Control Center" />
            <CCPage
                title="Límites y consumo"
                subtitle="Consumo real de cada organización contra los límites de su versión de plan. Superar un límite marca OVER_LIMIT y bloquea solo la nueva acción relacionada; nunca se borra información ni se frenan cierres o asientos."
            >
                <Panel>
                    <form
                        className="mb-3 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/control-center/usage',
                                { ...filters, search },
                                { preserveState: true },
                            );
                        }}
                    >
                        <input
                            className={`${ccInput} max-w-xs`}
                            placeholder="Organización"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <select
                            className={`${ccInput} max-w-[200px]`}
                            value={filters.status ?? ''}
                            onChange={(e) =>
                                router.get('/control-center/usage', {
                                    ...filters,
                                    status: e.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Todos</option>
                            {['warning', 'high', 'at_limit', 'over_limit'].map(
                                (s) => (
                                    <option key={s} value={s}>
                                        {statusLabel[s]}
                                    </option>
                                ),
                            )}
                        </select>
                    </form>
                    <Table
                        headers={[
                            'Organización',
                            'Plan',
                            ...metrics.map((m) => labels[m]),
                            'Estado',
                        ]}
                        empty={
                            rows.length === 0 &&
                            'Sin organizaciones con ese filtro.'
                        }
                    >
                        {rows.map((row) => (
                            <tr key={row.organization_id}>
                                <td className="px-2 py-2">
                                    <Link
                                        href={`/control-center/organizations/${row.organization_id}?tab=usage`}
                                        className="font-semibold text-white"
                                    >
                                        {row.organization}
                                    </Link>
                                </td>
                                <td className="px-2 py-2">{row.plan ?? '—'}</td>
                                {metrics.map((metric) => {
                                    const m = row.metrics.find(
                                        (item) => item.metric === metric,
                                    );

                                    return (
                                        <td
                                            key={metric}
                                            className="min-w-28 px-2 py-2"
                                        >
                                            {m ? (
                                                <>
                                                    <span
                                                        className={`text-[11px] ${['over_limit', 'at_limit', 'high'].includes(m.status) ? 'font-bold text-[#f87171]' : ''}`}
                                                    >
                                                        {metric ===
                                                        'storage_bytes'
                                                            ? bytes(m.value)
                                                            : m.value}{' '}
                                                        /{' '}
                                                        {m.limit === null
                                                            ? '∞'
                                                            : metric ===
                                                                'storage_bytes'
                                                              ? bytes(m.limit)
                                                              : m.limit}
                                                    </span>
                                                    {m.limit !== null &&
                                                        m.limit > 0 && (
                                                            <Progress
                                                                percent={
                                                                    m.percent
                                                                }
                                                            />
                                                        )}
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                    );
                                })}
                                <td className="px-2 py-2">
                                    <Pill tone={statusTone[row.worst]}>
                                        {statusLabel[row.worst] ?? row.worst}
                                    </Pill>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
