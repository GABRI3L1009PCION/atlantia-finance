import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    Table,
    ccButton,
    ccGhost,
    ccInput,
    date,
} from '@/components/control-center/kit';

type AuditEvent = {
    id: number;
    action: string;
    actor: string | null;
    role: string | null;
    entity: string;
    organization: string | null;
    before: unknown;
    after: unknown;
    reason: string | null;
    ip: string | null;
    session: string | null;
    correlation_id: string | null;
    sensitive: boolean;
    at: string;
};
type Filters = {
    action?: string;
    actor?: string;
    organization_id?: number;
    sensitive?: boolean;
    from?: string;
    to?: string;
};

export default function Audit({
    events,
    filters,
    actions,
}: {
    events: AuditEvent[];
    filters: Filters;
    actions: string[];
}) {
    const [draft, setDraft] = useState<Filters>(filters);
    const [open, setOpen] = useState<number | null>(null);

    return (
        <>
            <Head title="Auditoría SaaS · Control Center" />
            <CCPage
                title="Auditoría SaaS"
                subtitle="Registro inmutable de cada acción del personal SaaS: quién, qué, cuándo, antes, después, motivo, IP y correlación. No se puede editar ni borrar."
            >
                <Panel>
                    <form
                        className="mb-3 flex flex-wrap items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(
                                '/control-center/audit',
                                Object.fromEntries(
                                    Object.entries(draft).filter(
                                        ([, v]) =>
                                            v !== '' &&
                                            v !== undefined &&
                                            v !== false,
                                    ),
                                ),
                                { preserveState: true },
                            );
                        }}
                    >
                        <select
                            className={`${ccInput} max-w-[240px]`}
                            value={draft.action ?? ''}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    action: e.target.value || undefined,
                                })
                            }
                        >
                            <option value="">Toda acción</option>
                            {actions.map((a) => (
                                <option key={a} value={a}>
                                    {a}
                                </option>
                            ))}
                        </select>
                        <input
                            className={`${ccInput} max-w-[180px]`}
                            placeholder="Actor"
                            value={draft.actor ?? ''}
                            onChange={(e) =>
                                setDraft({ ...draft, actor: e.target.value })
                            }
                        />
                        <input
                            type="date"
                            className={`${ccInput} max-w-[160px]`}
                            value={draft.from ?? ''}
                            onChange={(e) =>
                                setDraft({ ...draft, from: e.target.value })
                            }
                        />
                        <input
                            type="date"
                            className={`${ccInput} max-w-[160px]`}
                            value={draft.to ?? ''}
                            onChange={(e) =>
                                setDraft({ ...draft, to: e.target.value })
                            }
                        />
                        <label className="flex items-center gap-2 text-xs text-slate-300">
                            <input
                                type="checkbox"
                                checked={Boolean(draft.sensitive)}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        sensitive: e.target.checked,
                                    })
                                }
                            />{' '}
                            Solo sensibles
                        </label>
                        <button className={ccButton}>Filtrar</button>
                    </form>
                    <Table
                        headers={[
                            'Fecha',
                            'Acción',
                            'Actor',
                            'Entidad',
                            'Organización',
                            'Motivo',
                            '',
                        ]}
                        empty={
                            events.length === 0 && 'Sin eventos con ese filtro.'
                        }
                    >
                        {events.map((event) => (
                            <Fragment key={event.id}>
                                <tr>
                                    <td className="px-2 py-1.5 whitespace-nowrap">
                                        {date(event.at, true)}
                                    </td>
                                    <td className="px-2 py-1.5 font-mono text-white">
                                        {event.action}
                                        {event.sensitive && (
                                            <Pill tone="red" dot={false}>
                                                sensible
                                            </Pill>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {event.actor ?? 'Sistema'}
                                        {event.role && (
                                            <span className="block text-[10px] text-slate-500">
                                                {event.role}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 font-mono text-[11px]">
                                        {event.entity}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {event.organization ?? '—'}
                                    </td>
                                    <td className="max-w-xs px-2 py-1.5 text-slate-400">
                                        {event.reason ?? '—'}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        <button
                                            type="button"
                                            className={ccGhost}
                                            onClick={() =>
                                                setOpen(
                                                    open === event.id
                                                        ? null
                                                        : event.id,
                                                )
                                            }
                                        >
                                            {open === event.id
                                                ? 'Ocultar'
                                                : 'Detalle'}
                                        </button>
                                    </td>
                                </tr>
                                {open === event.id && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="bg-black/20 p-3"
                                        >
                                            <div className="grid gap-3 md:grid-cols-2">
                                                <div>
                                                    <p className="mb-1 text-[11px] font-bold text-slate-400">
                                                        Antes
                                                    </p>
                                                    <pre className="max-h-60 overflow-auto rounded bg-black/40 p-2 text-[11px] text-slate-300">
                                                        {JSON.stringify(
                                                            event.before,
                                                            null,
                                                            2,
                                                        )}
                                                    </pre>
                                                </div>
                                                <div>
                                                    <p className="mb-1 text-[11px] font-bold text-slate-400">
                                                        Después
                                                    </p>
                                                    <pre className="max-h-60 overflow-auto rounded bg-black/40 p-2 text-[11px] text-slate-300">
                                                        {JSON.stringify(
                                                            event.after,
                                                            null,
                                                            2,
                                                        )}
                                                    </pre>
                                                </div>
                                            </div>
                                            <p className="mt-2 font-mono text-[10px] text-slate-500">
                                                IP {event.ip ?? '—'} · sesión{' '}
                                                {event.session ?? '—'} ·
                                                correlación{' '}
                                                {event.correlation_id ?? '—'}
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
