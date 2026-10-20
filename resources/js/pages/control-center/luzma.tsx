import { Head, Link, router } from '@inertiajs/react';
import { Bot, Power } from 'lucide-react';
import { useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    Progress,
    Table,
    ago,
    healthTone,
    panel,
    statusTone,
} from '@/components/control-center/kit';
import { ConfirmDialog } from '@/components/office-admin/kit';

type Status = {
    enabled: boolean;
    resident: boolean;
    disabled_message: string;
    gateway: boolean;
    model_loaded: boolean;
    status: string;
    model: string | null;
    latency_ms: number | null;
    detail: string | null;
    gateway_url: string;
    gpu: string | null;
    vram: string | null;
    requests_today: number;
    successful_today: number;
    errors_today: number;
    average_latency_ms: number | null;
    uptime_24h: number | null;
    queue: number;
    month_requests: number;
};
type Usage = {
    organization_id: number;
    tenant: string;
    requests: number;
    successful: number;
    failed: number;
    latency_ms: number | null;
    context_bytes: number | null;
    limit: number | null;
    percent: number | null;
};
type Tool = {
    key: string;
    description: string;
    module: string;
    risk: string;
    risk_label: string;
    status: string;
    status_label: string;
    reason: string | null;
    usage_30d: number;
    failures_30d: number;
    last_error: string | null;
};

const tabs: Record<string, [string, string]> = {
    status: ['Estado de Luzma', '/control-center/luzma'],
    usage: ['Consumo IA', '/control-center/luzma/usage'],
    tools: ['Herramientas', '/control-center/luzma/tools'],
};
const riskTone: Record<string, string> = {
    read_only: 'green',
    prepare: 'blue',
    low_risk_write: 'amber',
    sensitive_write: 'red',
    critical: 'red',
};

function LuzmaSwitchCard({
    status,
    canManage,
}: {
    status: Status;
    canManage: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div
            className={`rounded-xl border p-4 ${status.enabled ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-red-500/40 bg-red-500/10'}`}
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span
                        className={`grid size-10 place-items-center rounded-full ${status.enabled ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'}`}
                    >
                        <Power className="size-5" />
                    </span>
                    <div>
                        <p className="font-black text-white">
                            Luzma está{' '}
                            {status.enabled ? 'encendida' : 'apagada'} para
                            todas las firmas
                        </p>
                        <p className="text-xs text-slate-400">
                            {status.enabled
                                ? 'Solo este interruptor la apaga. Si el modelo falla, sigue respondiendo en modo básico y el sistema la reinicia sola.'
                                : status.disabled_message}
                        </p>
                    </div>
                </div>
                {canManage && (
                    <button
                        type="button"
                        onClick={() => setOpen(true)}
                        className={`rounded-lg px-4 py-2 text-sm font-bold text-white ${status.enabled ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'}`}
                    >
                        {status.enabled ? 'Apagar Luzma' : 'Encender Luzma'}
                    </button>
                )}
            </div>
            <ConfirmDialog
                open={open}
                tone={status.enabled ? 'danger' : 'primary'}
                icon={<Power className="size-7" />}
                title={
                    status.enabled
                        ? '¿Apagar Luzma para todas las firmas?'
                        : '¿Encender Luzma?'
                }
                description={
                    status.enabled
                        ? 'Ninguna firma ni usuario podrá usar Luzma mientras esté apagada, y los recordatorios e insights automáticos se detienen. El resto de Atlantia sigue funcionando.'
                        : 'Luzma volverá a estar disponible de inmediato para todas las firmas.'
                }
                noteLabel="Motivo (queda en la bitácora)"
                noteRequired
                confirmLabel={status.enabled ? 'Sí, apagar' : 'Sí, encender'}
                onConfirm={(reason) => {
                    router.post(
                        '/control-center/luzma/switch',
                        { enabled: !status.enabled, reason },
                        { preserveScroll: true },
                    );
                    setOpen(false);
                }}
                onClose={() => setOpen(false)}
            />
        </div>
    );
}

export default function Luzma({
    section,
    status,
    usage,
    tools,
    tool_states,
    can_manage,
}: {
    section: string;
    status: Status;
    usage: Usage[];
    tools: Tool[];
    tool_states: Record<string, string>;
    can_manage: boolean;
}) {
    return (
        <>
            <Head title="Luzma · Control Center" />
            <CCPage
                title={tabs[section][0]}
                subtitle="FastAPI → Ollama → modelo local. El Control Center ve cantidades, latencia y errores; nunca el contenido de las conversaciones de las organizaciones."
            >
                <nav className="flex gap-1">
                    {Object.entries(tabs).map(([key, [label, href]]) => (
                        <Link
                            key={key}
                            href={href}
                            className={`rounded-full px-3 py-1.5 text-xs font-semibold ${section === key ? 'bg-[#8b5cf6] text-white' : 'border border-white/10 text-slate-300'}`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>
                {section === 'status' && (
                    <LuzmaSwitchCard status={status} canManage={can_manage} />
                )}
                {section === 'status' && (
                    <div className="grid gap-3 xl:grid-cols-3">
                        <Panel
                            title="Estado"
                            icon={<Bot className="size-4 text-[#c4b5fd]" />}
                        >
                            <dl className="grid gap-2 text-xs">
                                {[
                                    [
                                        'Estado',
                                        <Pill
                                            key="s"
                                            tone={healthTone[status.status]}
                                        >
                                            {{
                                                healthy: 'Saludable',
                                                degraded: 'Degradado',
                                                down: 'Caído',
                                                maintenance:
                                                    'Apagada por el administrador',
                                            }[status.status] ?? status.status}
                                        </Pill>,
                                    ],
                                    [
                                        'Gateway FastAPI',
                                        status.gateway
                                            ? 'Responde'
                                            : 'No responde',
                                    ],
                                    [
                                        'Ollama / modelo instalado',
                                        status.model_loaded ? 'Sí' : 'No',
                                    ],
                                    [
                                        'Modelo en memoria (sin arranque en frío)',
                                        status.resident ? 'Sí' : 'No',
                                    ],
                                    ['Modelo', status.model ?? 'No reportado'],
                                    [
                                        'Latencia del chequeo',
                                        status.latency_ms === null
                                            ? '—'
                                            : `${status.latency_ms} ms`,
                                    ],
                                    [
                                        'GPU',
                                        status.gpu ??
                                            'No reportada por el gateway',
                                    ],
                                    ['VRAM', status.vram ?? 'No reportada'],
                                    [
                                        'Uptime 24 h',
                                        status.uptime_24h === null
                                            ? '—'
                                            : `${status.uptime_24h}%`,
                                    ],
                                    ['URL del gateway', status.gateway_url],
                                ].map(([label, value], index) => (
                                    <div
                                        key={index}
                                        className="flex justify-between gap-3"
                                    >
                                        <dt className="text-slate-500">
                                            {label}
                                        </dt>
                                        <dd className="text-right text-slate-200">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {status.detail && (
                                <p className="mt-2 text-xs text-[#fbbf24]">
                                    {status.detail}
                                </p>
                            )}
                        </Panel>
                        <div className="grid gap-3 sm:grid-cols-2 xl:col-span-2">
                            {[
                                ['Respuestas hoy', status.requests_today],
                                ['Exitosas hoy', status.successful_today],
                                ['Con error hoy', status.errors_today],
                                [
                                    'Latencia promedio',
                                    status.average_latency_ms === null
                                        ? '—'
                                        : `${(status.average_latency_ms / 1000).toFixed(1)} s`,
                                ],
                                ['Consultas del mes', status.month_requests],
                                ['En cola (mantenimiento)', status.queue],
                            ].map(([label, value]) => (
                                <div
                                    key={String(label)}
                                    className={`${panel} p-4`}
                                >
                                    <p className="text-xs text-slate-400">
                                        {label}
                                    </p>
                                    <p className="mt-1 text-2xl font-black text-white">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
                {section === 'usage' && (
                    <Panel title="Consumo del mes por organización">
                        <Table
                            headers={[
                                'Organización',
                                'Consultas',
                                'Exitosas',
                                'Fallidas',
                                'Latencia',
                                'Contexto prom.',
                                'Límite',
                            ]}
                            empty={
                                usage.length === 0 &&
                                'Sin consumo de Luzma este mes.'
                            }
                        >
                            {usage.map((row) => (
                                <tr key={row.organization_id}>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={`/control-center/organizations/${row.organization_id}?tab=usage`}
                                            className="text-white"
                                        >
                                            {row.tenant}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.requests}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.successful}
                                    </td>
                                    <td className="px-2 py-2 text-[#f87171]">
                                        {row.failed}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.latency_ms === null
                                            ? '—'
                                            : `${row.latency_ms} ms`}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.context_bytes === null
                                            ? '—'
                                            : `${Math.round(row.context_bytes / 1024)} KB`}
                                    </td>
                                    <td className="w-40 px-2 py-2">
                                        {row.limit === null ? (
                                            'Ilimitado'
                                        ) : (
                                            <>
                                                {row.requests} / {row.limit}
                                                <Progress
                                                    percent={row.percent}
                                                />
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                )}
                {section === 'tools' && (
                    <Panel title={`${tools.length} herramientas`}>
                        <Table
                            headers={[
                                'Herramienta',
                                'Módulo',
                                'Riesgo',
                                'Uso 30 d',
                                'Fallas',
                                'Último error',
                                'Estado',
                                '',
                            ]}
                        >
                            {tools.map((tool) => (
                                <tr key={tool.key}>
                                    <td className="max-w-md px-2 py-2">
                                        <span className="font-mono text-white">
                                            {tool.key}
                                        </span>
                                        <span className="block text-[11px] text-slate-500">
                                            {tool.description}
                                        </span>
                                    </td>
                                    <td className="px-2 py-2">{tool.module}</td>
                                    <td className="px-2 py-2">
                                        <Pill tone={riskTone[tool.risk]}>
                                            {tool.risk_label}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-2">
                                        {tool.usage_30d}
                                    </td>
                                    <td className="px-2 py-2">
                                        {tool.failures_30d}
                                    </td>
                                    <td className="px-2 py-2">
                                        {ago(tool.last_error)}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Pill tone={statusTone[tool.status]}>
                                            {tool.status_label}
                                        </Pill>
                                        {tool.reason && (
                                            <span className="block text-[10px] text-slate-500">
                                                {tool.reason}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-2 py-2">
                                        {can_manage && (
                                            <select
                                                className="h-8 rounded-lg border border-white/10 bg-[#0b0e14] px-2 text-xs text-slate-200"
                                                value={tool.status}
                                                onChange={(e) => {
                                                    const reason =
                                                        window.prompt(
                                                            'Motivo del cambio',
                                                        );

                                                    if (reason) {
                                                        router.post(
                                                            `/control-center/luzma/tools/${tool.key}`,
                                                            {
                                                                status: e.target
                                                                    .value,
                                                                reason,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }
                                                }}
                                            >
                                                {Object.entries(
                                                    tool_states,
                                                ).map(([key, label]) => (
                                                    <option
                                                        key={key}
                                                        value={key}
                                                    >
                                                        {label}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    </Panel>
                )}
            </CCPage>
        </>
    );
}
