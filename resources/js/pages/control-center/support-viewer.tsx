import { Head, Link, router } from '@inertiajs/react';
import { Eye, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CCPage, Panel, Table, ccGhost } from '@/components/control-center/kit';

type Session = {
    id: number;
    organization: string;
    organization_id: number;
    reason: string;
    ticket: string | null;
    scopes: string[];
    expires_at: string;
};

const sectionLabels: Record<string, string> = {
    offices: 'Oficinas',
    companies: 'Empresas',
    users: 'Usuarios',
    roles: 'Roles asignados',
    fiscal_profiles: 'Perfiles fiscales',
    fel: 'FEL (sin secretos)',
    periods: 'Períodos',
    entries: 'Asientos recientes',
    pending_entries: 'Asientos pendientes',
    files: 'Archivos',
    client_documents: 'Documentos del portal',
    api_tokens: 'Llaves de API',
    webhooks: 'Webhooks',
    failed_deliveries: 'Entregas fallidas',
    api_errors: 'Errores de API',
    employees: 'Empleados',
};

function cell(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

function Remaining({ expiresAt }: { expiresAt: string }) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const seconds = Math.max(
        0,
        Math.floor((new Date(expiresAt).getTime() - now) / 1000),
    );

    return (
        <span className="font-mono">
            {Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}
        </span>
    );
}

export default function SupportViewer({
    session,
    scope,
    scope_labels,
    data,
}: {
    session: Session;
    scope: string;
    scope_labels: Record<string, string>;
    data: Record<string, unknown>;
}) {
    return (
        <>
            <Head title={`Modo soporte · ${session.organization}`} />
            <CCPage
                title={`Modo soporte · ${session.organization}`}
                subtitle="Vista de solo lectura dentro del alcance aprobado. Cada sección que abres queda registrada en la auditoría de la plataforma."
                actions={
                    <button
                        type="button"
                        className={`${ccGhost} border-[#ef4444]/40 text-[#fca5a5]`}
                        onClick={() => {
                            const reason = window.prompt(
                                'Motivo para terminar el acceso',
                                'Diagnóstico terminado',
                            );

                            if (reason) {
                                router.post(
                                    `/control-center/support-access/${session.id}/revoke`,
                                    { reason },
                                );
                            }
                        }}
                    >
                        Terminar modo soporte
                    </button>
                }
            >
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-[#f59e0b]/40 bg-[#f59e0b]/10 px-4 py-3 text-sm text-[#fde68a]">
                    <Eye className="size-4" /> <strong>MODO SOPORTE</strong> ·{' '}
                    {session.reason}
                    {session.ticket && <> · Ticket {session.ticket}</>}
                    <span className="ml-auto inline-flex items-center gap-1">
                        <Timer className="size-4" /> Vence en{' '}
                        <Remaining expiresAt={session.expires_at} />
                    </span>
                </div>
                <nav className="flex flex-wrap gap-1">
                    {session.scopes.map((key) => (
                        <Link
                            key={key}
                            href={`/control-center/support-access/${session.id}/view/${key}`}
                            className={`rounded-full px-3 py-1.5 text-xs font-semibold ${scope === key ? 'bg-[#c8102e] text-white' : 'border border-white/10 text-slate-300'}`}
                            title={scope_labels[key]}
                        >
                            {scope_labels[key]?.split(':')[0] ?? key}
                        </Link>
                    ))}
                </nav>
                {Object.entries(data).map(([key, value]) => (
                    <Panel key={key} title={sectionLabels[key] ?? key}>
                        {Array.isArray(value) ? (
                            value.length === 0 ? (
                                <p className="py-4 text-center text-xs text-slate-500">
                                    Sin registros.
                                </p>
                            ) : (
                                <Table
                                    headers={Object.keys(
                                        value[0] as Record<string, unknown>,
                                    )}
                                >
                                    {(value as Record<string, unknown>[]).map(
                                        (row, index) => (
                                            <tr key={index}>
                                                {Object.values(row).map(
                                                    (v, i) => (
                                                        <td
                                                            key={i}
                                                            className="max-w-xs truncate px-2 py-1.5"
                                                            title={cell(v)}
                                                        >
                                                            {cell(v)}
                                                        </td>
                                                    ),
                                                )}
                                            </tr>
                                        ),
                                    )}
                                </Table>
                            )
                        ) : (
                            <p className="text-2xl font-black text-white">
                                {cell(value)}
                            </p>
                        )}
                    </Panel>
                ))}
            </CCPage>
        </>
    );
}
