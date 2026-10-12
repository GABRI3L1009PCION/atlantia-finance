import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    CCPage,
    CopyButton,
    Panel,
    Pill,
    Table,
    ccButton,
    ccInput,
    date,
} from '@/components/control-center/kit';

type Entry = {
    at: string;
    source: string;
    severity: string;
    message: string;
    context: string | null;
    correlation_id: string | null;
    organization_id: number | null;
};
type Filters = {
    source?: string;
    severity?: string;
    search?: string;
    correlation_id?: string;
    organization_id?: number;
    date?: string;
};

const sources: Record<string, string> = {
    application: 'Aplicación',
    queue: 'Colas',
    integration: 'Integraciones',
    provider: 'Proveedores',
    security: 'Seguridad',
};
const severityTone: Record<string, string> = {
    debug: 'slate',
    info: 'blue',
    notice: 'blue',
    warning: 'amber',
    error: 'red',
    critical: 'red',
    alert: 'red',
    emergency: 'red',
};

export default function Logs({
    entries,
    filters,
    environment,
    organizations,
}: {
    entries: Entry[];
    filters: Filters;
    environment: string;
    organizations: { id: number; name: string }[];
}) {
    const [draft, setDraft] = useState<Filters>(filters);
    const names = Object.fromEntries(organizations.map((o) => [o.id, o.name]));
    const apply = (next: Filters) =>
        router.get(
            '/control-center/logs',
            Object.fromEntries(
                Object.entries(next).filter(
                    ([, v]) => v !== '' && v !== undefined,
                ),
            ),
            { preserveState: true },
        );

    return (
        <>
            <Head title="Logs · Control Center" />
            <CCPage
                title="Logs"
                subtitle={`Entorno: ${environment}. Los mensajes se sanean antes de mostrarse: contraseñas, tokens, secretos y números de tarjeta aparecen enmascarados.`}
            >
                <Panel>
                    <form
                        className="mb-3 flex flex-wrap items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply(draft);
                        }}
                    >
                        <select
                            className={`${ccInput} max-w-[160px]`}
                            value={draft.source ?? ''}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    source: e.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Todo origen</option>
                            {Object.entries(sources).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <select
                            className={`${ccInput} max-w-[160px]`}
                            value={draft.severity ?? ''}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    severity: e.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Toda severidad</option>
                            {['info', 'warning', 'error', 'critical'].map(
                                (s) => (
                                    <option key={s} value={s}>
                                        ≥ {s}
                                    </option>
                                ),
                            )}
                        </select>
                        <select
                            className={`${ccInput} max-w-[200px]`}
                            value={draft.organization_id ?? ''}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    organization_id: e.target.value
                                        ? Number(e.target.value)
                                        : undefined,
                                })
                            }
                        >
                            <option value="">Toda organización</option>
                            {organizations.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                        <input
                            type="date"
                            className={`${ccInput} max-w-[160px]`}
                            value={draft.date ?? ''}
                            onChange={(e) =>
                                setDraft({ ...draft, date: e.target.value })
                            }
                        />
                        <input
                            className={`${ccInput} max-w-[200px]`}
                            placeholder="Buscar texto"
                            value={draft.search ?? ''}
                            onChange={(e) =>
                                setDraft({ ...draft, search: e.target.value })
                            }
                        />
                        <input
                            className={`${ccInput} max-w-[220px] font-mono`}
                            placeholder="Correlation ID"
                            value={draft.correlation_id ?? ''}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    correlation_id: e.target.value,
                                })
                            }
                        />
                        <button className={ccButton}>Filtrar</button>
                    </form>
                    <Table
                        headers={[
                            'Fecha',
                            'Origen',
                            'Severidad',
                            'Mensaje',
                            'Organización',
                            'Correlación',
                        ]}
                        empty={
                            entries.length === 0 &&
                            'Sin registros con ese filtro.'
                        }
                    >
                        {entries.map((entry, index) => (
                            <tr key={index}>
                                <td className="px-2 py-1.5 whitespace-nowrap">
                                    {date(entry.at, true)}
                                </td>
                                <td className="px-2 py-1.5">
                                    {sources[entry.source] ?? entry.source}
                                </td>
                                <td className="px-2 py-1.5">
                                    <Pill tone={severityTone[entry.severity]}>
                                        {entry.severity}
                                    </Pill>
                                </td>
                                <td className="max-w-xl px-2 py-1.5">
                                    <span className="text-slate-200">
                                        {entry.message}
                                    </span>
                                    {entry.context && (
                                        <span
                                            className="block truncate font-mono text-[10px] text-slate-500"
                                            title={entry.context}
                                        >
                                            {entry.context}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-1.5">
                                    {entry.organization_id === null
                                        ? '—'
                                        : (names[entry.organization_id] ??
                                          `#${entry.organization_id}`)}
                                </td>
                                <td className="px-2 py-1.5">
                                    {entry.correlation_id ? (
                                        <span className="inline-flex items-center gap-1 font-mono text-[10px]">
                                            <button
                                                type="button"
                                                className="text-[#93c5fd] hover:underline"
                                                onClick={() =>
                                                    apply({
                                                        correlation_id:
                                                            entry.correlation_id ??
                                                            undefined,
                                                    })
                                                }
                                            >
                                                {entry.correlation_id.slice(
                                                    0,
                                                    8,
                                                )}
                                                …
                                            </button>
                                            <CopyButton
                                                value={entry.correlation_id}
                                            />
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
