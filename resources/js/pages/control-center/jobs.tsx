import { Head, router } from '@inertiajs/react';
import {
    CCPage,
    Panel,
    Pill,
    Table,
    ago,
    ccGhost,
    ccInput,
    date,
    panel,
    statusTone,
} from '@/components/control-center/kit';

type Queue = {
    pending: number;
    reserved: number;
    oldest_seconds: number | null;
    failed_24h: number;
    failed_total: number;
    processed_1h: number;
    completed_1h: number;
    failed_rate: number | null;
    workers_active: boolean;
    by_queue: { queue: string; pending: number }[];
};
type Group = {
    signature: string;
    job: string;
    class: string;
    cause: string;
    count: number;
    first: string;
    last: string;
    retryable: boolean;
    policy: string;
    uuids: string[];
    tenants: string[];
};
type Run = {
    id: number;
    job: string;
    queue: string | null;
    tenant: string | null;
    status: string;
    attempts: number;
    duration_ms: number | null;
    created: string;
    failed: string | null;
    exception: string | null;
    correlation_id: string | null;
};
type Task = {
    task: string;
    label: string;
    expression: string;
    last_run: string | null;
    next_run: string | null;
    duration_ms: number | null;
    status: string;
    last_error: string | null;
};

export default function Jobs({
    queue,
    failed_groups,
    runs,
    scheduler,
    filters,
    can_retry,
}: {
    queue: Queue;
    failed_groups: Group[];
    runs: Run[];
    scheduler: Task[];
    filters: { status?: string; search?: string };
    can_retry: boolean;
}) {
    return (
        <>
            <Head title="Jobs y colas · Control Center" />
            <CCPage
                title="Jobs y colas"
                subtitle="Salud de la cola, errores agrupados por causa y tareas programadas. Solo se reintentan jobs idempotentes; pagos, certificación FEL y contabilizaciones se recuperan con su propio servicio."
            >
                <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    {[
                        ['En cola', queue.pending],
                        ['En proceso', queue.reserved],
                        [
                            'Job más antiguo',
                            queue.oldest_seconds === null
                                ? '—'
                                : `${Math.round(queue.oldest_seconds / 60)} min`,
                        ],
                        ['Fallidos 24 h', queue.failed_24h],
                        ['Procesados 1 h', queue.processed_1h],
                        [
                            'Tasa de falla 1 h',
                            queue.failed_rate === null
                                ? '—'
                                : `${queue.failed_rate}%`,
                        ],
                    ].map(([label, value]) => (
                        <div key={String(label)} className={`${panel} p-4`}>
                            <p className="text-xs text-slate-400">{label}</p>
                            <p className="mt-1 text-2xl font-black text-white">
                                {value}
                            </p>
                        </div>
                    ))}
                </div>
                {!queue.workers_active && queue.pending > 0 && (
                    <div className="rounded-xl border border-[#f59e0b]/30 bg-[#f59e0b]/10 p-3 text-sm text-[#fde68a]">
                        Hay trabajos en cola pero ningún worker los está
                        procesando. Inicia «php artisan queue:work».
                    </div>
                )}
                <Panel title="Fallas agrupadas por causa">
                    <Table
                        headers={[
                            'Job',
                            'Causa',
                            'Veces',
                            'Organizaciones',
                            'Primera / última',
                            'Política',
                            '',
                        ]}
                        empty={
                            failed_groups.length === 0 &&
                            'No hay jobs fallidos.'
                        }
                    >
                        {failed_groups.map((group) => (
                            <tr key={group.signature}>
                                <td className="px-2 py-2 font-mono text-white">
                                    {group.job}
                                </td>
                                <td className="max-w-md px-2 py-2 text-[#fca5a5]">
                                    {group.cause}
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    {group.count}
                                </td>
                                <td className="px-2 py-2 text-[11px]">
                                    {group.tenants.join(', ') || '—'}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {date(group.first, true)}
                                    <span className="block text-slate-500">
                                        {ago(group.last)}
                                    </span>
                                </td>
                                <td className="max-w-xs px-2 py-2 text-[11px] text-slate-400">
                                    {group.policy}
                                </td>
                                <td className="px-2 py-2">
                                    {can_retry && group.retryable && (
                                        <button
                                            type="button"
                                            className={ccGhost}
                                            onClick={() =>
                                                router.post(
                                                    '/control-center/jobs/retry',
                                                    { uuids: group.uuids },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Reintentar {group.count}
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
                <Panel title="Tareas programadas">
                    <Table
                        headers={[
                            'Tarea',
                            'Expresión',
                            'Última ejecución',
                            'Duración',
                            'Próxima',
                            'Estado',
                            'Último error',
                        ]}
                        empty={
                            scheduler.length === 0 && 'Sin tareas programadas.'
                        }
                    >
                        {scheduler.map((task) => (
                            <tr key={task.task}>
                                <td className="px-2 py-2">
                                    <span className="text-white">
                                        {task.label}
                                    </span>
                                    <span className="block font-mono text-[10px] text-slate-500">
                                        {task.task}
                                    </span>
                                </td>
                                <td className="px-2 py-2 font-mono">
                                    {task.expression}
                                </td>
                                <td className="px-2 py-2">
                                    {ago(task.last_run)}
                                </td>
                                <td className="px-2 py-2">
                                    {task.duration_ms === null
                                        ? '—'
                                        : `${task.duration_ms} ms`}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {date(task.next_run, true)}
                                </td>
                                <td className="px-2 py-2">
                                    <Pill
                                        tone={
                                            task.status === 'never'
                                                ? 'slate'
                                                : statusTone[task.status]
                                        }
                                    >
                                        {task.status === 'never'
                                            ? 'Sin ejecutar'
                                            : task.status}
                                    </Pill>
                                </td>
                                <td className="max-w-xs px-2 py-2 text-[11px] text-[#fca5a5]">
                                    {task.last_error ?? ''}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
                <Panel
                    title="Ejecuciones recientes"
                    action={
                        <div className="flex gap-2">
                            <select
                                className={`${ccInput} h-8 w-36`}
                                value={filters.status ?? ''}
                                onChange={(e) =>
                                    router.get(
                                        '/control-center/jobs',
                                        {
                                            ...filters,
                                            status: e.target.value || undefined,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <option value="">Todos</option>
                                {[
                                    'running',
                                    'completed',
                                    'failed',
                                    'retrying',
                                ].map((s) => (
                                    <option key={s} value={s}>
                                        {s}
                                    </option>
                                ))}
                            </select>
                        </div>
                    }
                >
                    <Table
                        headers={[
                            'Job',
                            'Cola',
                            'Organización',
                            'Estado',
                            'Intentos',
                            'Duración',
                            'Creado',
                            'Excepción',
                        ]}
                        empty={
                            runs.length === 0 &&
                            'Aún no hay ejecuciones registradas.'
                        }
                    >
                        {runs.map((run) => (
                            <tr key={run.id}>
                                <td className="px-2 py-1.5 font-mono text-white">
                                    {run.job}
                                </td>
                                <td className="px-2 py-1.5">
                                    {run.queue ?? '—'}
                                </td>
                                <td className="px-2 py-1.5">
                                    {run.tenant ?? '—'}
                                </td>
                                <td className="px-2 py-1.5">
                                    <Pill tone={statusTone[run.status]}>
                                        {run.status}
                                    </Pill>
                                </td>
                                <td className="px-2 py-1.5">{run.attempts}</td>
                                <td className="px-2 py-1.5">
                                    {run.duration_ms === null
                                        ? '—'
                                        : `${run.duration_ms} ms`}
                                </td>
                                <td className="px-2 py-1.5 whitespace-nowrap">
                                    {date(run.created, true)}
                                </td>
                                <td
                                    className="max-w-xs truncate px-2 py-1.5 text-[11px] text-[#fca5a5]"
                                    title={run.exception ?? undefined}
                                >
                                    {run.exception ?? ''}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
            </CCPage>
        </>
    );
}
