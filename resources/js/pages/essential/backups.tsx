import { Head } from '@inertiajs/react';
import { Database, ShieldCheck, TriangleAlert } from 'lucide-react';
import { Stat, formatBytes } from '@/components/essential/kit';
import {
    PageHeader,
    Shell,
    formatDate,
    relativeTime,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Props = {
    backups: {
        enabled: boolean;
        schedule: string;
        last: {
            at: string;
            size_bytes: number | null;
            verified: boolean;
        } | null;
        runs: {
            id: number;
            status: string;
            size_bytes: number | null;
            includes_files: boolean;
            uploaded: boolean;
            error: string | null;
            at: string;
        }[];
        failed_last_week: number;
    };
};

export default function EssentialBackups({ backups }: Props) {
    const healthy =
        backups.enabled &&
        backups.last !== null &&
        backups.failed_last_week === 0;

    return (
        <>
            <Head title="Respaldos" />
            <Shell>
                <PageHeader
                    title="Respaldos"
                    subtitle="Tu información se copia automáticamente cada noche y cada copia se verifica antes de guardarse"
                />
                <div
                    className={`flex items-start gap-3 rounded-xl border p-4 ${healthy ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}
                >
                    {healthy ? (
                        <ShieldCheck className="mt-0.5 size-5 shrink-0" />
                    ) : (
                        <TriangleAlert className="mt-0.5 size-5 shrink-0" />
                    )}
                    <p className="text-sm font-semibold">
                        {healthy
                            ? 'Tu información está protegida: el último respaldo se verificó correctamente.'
                            : !backups.enabled
                              ? 'Los respaldos automáticos están desactivados en este servidor. Avisa a soporte de Atlantia.'
                              : backups.last === null
                                ? 'Todavía no hay un respaldo completado.'
                                : `Hubo ${backups.failed_last_week} respaldo(s) fallido(s) en los últimos 7 días. Soporte de Atlantia fue notificado.`}
                    </p>
                </div>

                <section className="grid gap-3 sm:grid-cols-3">
                    <Stat
                        label="Último respaldo"
                        value={
                            backups.last ? relativeTime(backups.last.at) : '—'
                        }
                        hint={
                            backups.last
                                ? formatDate(backups.last.at, true)
                                : undefined
                        }
                        tone={backups.last ? 'green' : 'amber'}
                    />
                    <Stat
                        label="Tamaño"
                        value={formatBytes(backups.last?.size_bytes)}
                        hint="Base de datos y archivos"
                    />
                    <Stat
                        label="Programación"
                        value={<Database className="size-7" />}
                        hint={backups.schedule}
                    />
                </section>

                <Card title="Historial reciente">
                    <Table
                        headers={[
                            'Fecha',
                            'Resultado',
                            'Contenido',
                            'Tamaño',
                            'Copia externa',
                        ]}
                        empty={
                            backups.runs.length === 0 &&
                            'Aún no se ha ejecutado ningún respaldo.'
                        }
                    >
                        {backups.runs.map((run) => (
                            <tr key={run.id}>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {formatDate(run.at, true)}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            run.status === 'succeeded'
                                                ? 'green'
                                                : 'red',
                                        )}
                                    >
                                        {run.status === 'succeeded'
                                            ? 'Verificado'
                                            : 'Falló'}
                                    </span>
                                    {run.error && (
                                        <p className="mt-1 max-w-sm text-[11px] text-red-700">
                                            {run.error}
                                        </p>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {run.includes_files
                                        ? 'Datos y archivos'
                                        : 'Solo datos'}
                                </td>
                                <td className="px-2 py-2">
                                    {formatBytes(run.size_bytes)}
                                </td>
                                <td className="px-2 py-2">
                                    {run.uploaded ? 'Sí' : 'No'}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
