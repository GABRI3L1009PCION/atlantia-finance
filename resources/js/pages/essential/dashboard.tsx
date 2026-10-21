import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Stat, formatBytes, priorityLabel } from '@/components/essential/kit';
import type { EssentialContext } from '@/components/essential/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    primaryButton,
    relativeTime,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';
import { statusLabel } from '@/lib/status-labels';

type Usage = {
    metric: string;
    label: string;
    value: number;
    limit: number | null;
    percent: number | null;
    status: string;
};

type Panel = {
    owner: boolean;
    kpis: {
        clients: number;
        open_tasks: number;
        overdue_tasks: number;
        to_review: number;
        obligations_due: number;
        obligations_overdue: number;
        invoices_awaiting_sat: number;
        invoiced_month_cents: number;
        invoices_month: number;
    };
    upcoming: {
        id: number;
        name: string;
        period: string;
        due_on: string;
        status: string;
        company: string;
        overdue: boolean;
    }[];
    my_tasks: {
        id: number;
        title: string;
        status: string;
        priority: string;
        due_at: string | null;
        company: string | null;
        overdue: boolean;
    }[];
    usage: Usage[];
    plan: { name: string; status: string | null; renews_on: string | null };
    backup: { at: string; size_bytes: number | null; verified: boolean } | null;
    activity: {
        event_id: number;
        description: string;
        created_at: string;
        destination: string | null;
    }[];
};

const usageValue = (row: Usage): string =>
    row.metric === 'storage_bytes'
        ? `${formatBytes(row.value)}${row.limit ? ` de ${formatBytes(row.limit)}` : ''}`
        : `${row.value}${row.limit ? ` de ${row.limit}` : ' (sin límite)'}`;

export default function EssentialDashboard({
    panel,
    essential,
}: {
    panel: Panel;
    essential: EssentialContext;
}) {
    const k = panel.kpis;

    return (
        <>
            <Head title="Inicio" />
            <Shell>
                <PageHeader
                    title={essential.owner ? 'Tu oficina contable' : 'Mi panel'}
                    subtitle={
                        essential.owner
                            ? `Plan ${panel.plan.name} · ${k.clients} cliente(s) · todo lo que necesitas para operar tu oficina`
                            : `Plan ${panel.plan.name} · ${k.clients} cliente(s) a tu cargo`
                    }
                    actions={
                        <>
                            <Link
                                href="/essential/tasks?new=1"
                                className={primaryButton}
                            >
                                <Plus className="size-4" /> Nueva tarea
                            </Link>
                            {essential.owner && (
                                <Link
                                    href="/office/companies"
                                    className={secondaryButton}
                                >
                                    Clientes
                                </Link>
                            )}
                        </>
                    }
                />

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        label="Tareas abiertas"
                        value={k.open_tasks}
                        hint={
                            k.overdue_tasks > 0
                                ? `${k.overdue_tasks} vencida(s)`
                                : 'Ninguna vencida'
                        }
                        tone={k.overdue_tasks > 0 ? 'red' : 'slate'}
                        href="/essential/tasks"
                    />
                    <Stat
                        label="Por revisar"
                        value={k.to_review}
                        hint="Trabajo que esperan tu aprobación"
                        tone={k.to_review > 0 ? 'amber' : 'slate'}
                        href="/essential/tasks?status=review"
                    />
                    <Stat
                        label="Obligaciones esta semana"
                        value={k.obligations_due}
                        hint={
                            k.obligations_overdue > 0
                                ? `${k.obligations_overdue} vencida(s)`
                                : 'Sin vencidas'
                        }
                        tone={k.obligations_overdue > 0 ? 'red' : 'blue'}
                        href="/essential/obligations"
                    />
                    <Stat
                        label="Facturas por emitir en SAT"
                        value={k.invoices_awaiting_sat}
                        hint={`${k.invoices_month} emitida(s) este mes · ${money(k.invoiced_month_cents)}`}
                        tone={k.invoices_awaiting_sat > 0 ? 'amber' : 'green'}
                        href="/essential/invoicing?status=awaiting_sat"
                    />
                </section>

                <div className="grid gap-4 xl:grid-cols-2">
                    <Card
                        title="Próximas obligaciones fiscales"
                        action={
                            <Link
                                href="/essential/obligations"
                                className={secondaryButton}
                            >
                                Ver calendario
                            </Link>
                        }
                    >
                        <Table
                            headers={[
                                'Obligación',
                                'Cliente',
                                'Vence',
                                'Estado',
                            ]}
                            empty={
                                panel.upcoming.length === 0 &&
                                'No hay obligaciones pendientes.'
                            }
                        >
                            {panel.upcoming.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-2 py-2">
                                        <span className="font-bold">
                                            {row.name}
                                        </span>
                                        <p className="text-[11px] text-slate-500">
                                            {row.period}
                                        </p>
                                    </td>
                                    <td className="px-2 py-2">{row.company}</td>
                                    <td
                                        className={`px-2 py-2 ${row.overdue ? 'font-bold text-red-700' : ''}`}
                                    >
                                        {formatDate(row.due_on)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className={toneBadge('slate')}>
                                            {statusLabel(row.status)}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    <Card
                        title={
                            essential.owner ? 'Tareas del equipo' : 'Mis tareas'
                        }
                        action={
                            <Link
                                href="/essential/tasks"
                                className={secondaryButton}
                            >
                                Ver tareas
                            </Link>
                        }
                    >
                        <Table
                            headers={['Tarea', 'Cliente', 'Vence', 'Prioridad']}
                            empty={
                                panel.my_tasks.length === 0 &&
                                'No hay tareas abiertas.'
                            }
                        >
                            {panel.my_tasks.map((task) => {
                                const [label, tone] = priorityLabel[
                                    task.priority
                                ] ?? [task.priority, 'slate'];

                                return (
                                    <tr key={task.id}>
                                        <td className="px-2 py-2">
                                            <Link
                                                href={`/work/tasks/${task.id}`}
                                                className="font-bold hover:text-[#640000]"
                                            >
                                                {task.title}
                                            </Link>
                                            <p className="text-[11px] text-slate-500">
                                                {statusLabel(task.status)}
                                            </p>
                                        </td>
                                        <td className="px-2 py-2">
                                            {task.company ?? '—'}
                                        </td>
                                        <td
                                            className={`px-2 py-2 ${task.overdue ? 'font-bold text-red-700' : ''}`}
                                        >
                                            {formatDate(task.due_at)}
                                        </td>
                                        <td className="px-2 py-2">
                                            <span className={toneBadge(tone)}>
                                                {label}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </Table>
                    </Card>
                </div>

                <div className="grid gap-4 xl:grid-cols-3">
                    <Card title="Uso de tu plan">
                        <div className="space-y-3">
                            {panel.usage.map((row) => (
                                <div key={row.metric}>
                                    <div className="flex justify-between text-sm">
                                        <span className="font-semibold">
                                            {row.label}
                                        </span>
                                        <span className="text-slate-600">
                                            {usageValue(row)}
                                        </span>
                                    </div>
                                    <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <span
                                            className={`block h-full ${row.status === 'over_limit' || row.status === 'high' ? 'bg-red-500' : row.status === 'warning' ? 'bg-amber-500' : 'bg-emerald-500'}`}
                                            style={{
                                                width: `${Math.min(100, row.percent ?? 0)}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                        <p className="mt-4 text-xs text-slate-500">
                            Plan {panel.plan.name}
                            {panel.plan.renews_on
                                ? ` · se renueva el ${formatDate(panel.plan.renews_on)}`
                                : ''}
                            .{' '}
                            {panel.owner && (
                                <Link
                                    href="/office/subscription"
                                    className="font-bold text-[#640000]"
                                >
                                    Administrar suscripción
                                </Link>
                            )}
                        </p>
                    </Card>

                    <Card title="Respaldo de tu información">
                        {panel.backup ? (
                            <>
                                <p className="text-sm">
                                    Último respaldo verificado{' '}
                                    <strong>
                                        {relativeTime(panel.backup.at)}
                                    </strong>
                                    .
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    {formatDate(panel.backup.at, true)} ·{' '}
                                    {formatBytes(panel.backup.size_bytes)} ·
                                    integridad comprobada
                                </p>
                            </>
                        ) : (
                            <p className="text-sm text-amber-800">
                                Aún no hay un respaldo registrado. Los respaldos
                                automáticos se ejecutan cada noche.
                            </p>
                        )}
                        {panel.owner && (
                            <Link
                                href="/essential/backups"
                                className={`${secondaryButton} mt-3`}
                            >
                                Ver historial
                            </Link>
                        )}
                    </Card>

                    {panel.owner && (
                        <Card title="Actividad reciente">
                            {panel.activity.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    Todavía no hay actividad registrada.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {panel.activity.map((event) => (
                                        <li
                                            key={event.event_id}
                                            className="flex justify-between gap-3"
                                        >
                                            <span>{event.description}</span>
                                            <span className="shrink-0 text-xs text-slate-500">
                                                {relativeTime(event.created_at)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <Link
                                href="/essential/audit"
                                className={`${secondaryButton} mt-3`}
                            >
                                Ver auditoría completa
                            </Link>
                        </Card>
                    )}
                </div>
            </Shell>
        </>
    );
}
