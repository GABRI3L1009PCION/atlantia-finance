import { Head, Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import {
    CCPage,
    Panel,
    Pill,
    Sparkline,
    Table,
    ago,
    ccButton,
    date,
    healthTone,
} from '@/components/control-center/kit';

type Service = {
    key: string;
    name: string;
    status: string;
    label: string;
    latency: number | null;
    uptime: number | null;
    last_check: string | null;
    latest_error: string | null;
    critical: boolean;
    depends: string[];
    history: number[];
};

export default function Services({
    services,
    selected,
    history,
}: {
    services: Service[];
    selected: string;
    history: {
        status: string;
        latency_ms: number | null;
        detail: string | null;
        checked_at: string;
    }[];
}) {
    const names = Object.fromEntries(services.map((s) => [s.key, s.name]));

    return (
        <>
            <Head title="Servicios · Control Center" />
            <CCPage
                title="Servicios"
                subtitle="Chequeos reales y de solo lectura (conexión, respuesta, cola, proveedor). Un servicio sin chequeo reciente se muestra como Desconocido, nunca como Operativo."
                actions={
                    <button
                        type="button"
                        className={ccButton}
                        onClick={() =>
                            router.post(
                                '/control-center/services/check',
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <RefreshCw className="size-4" /> Verificar ahora
                    </button>
                }
            >
                <Panel>
                    <Table
                        headers={[
                            'Servicio',
                            'Estado',
                            'Latencia',
                            'Uptime 24 h',
                            'Último chequeo',
                            'Detalle / último error',
                            'Depende de',
                            'Tendencia',
                        ]}
                        empty={services.length === 0 && 'Estado no disponible.'}
                    >
                        {services.map((service) => (
                            <tr
                                key={service.key}
                                className={
                                    selected === service.key
                                        ? 'bg-white/[0.03]'
                                        : ''
                                }
                            >
                                <td className="px-2 py-2">
                                    <Link
                                        href={`/control-center/services?service=${service.key}`}
                                        className="font-semibold text-white"
                                    >
                                        {service.name}
                                    </Link>
                                    {service.critical && (
                                        <span className="ml-1 text-[10px] text-[#e8b04b]">
                                            crítico
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <Pill tone={healthTone[service.status]}>
                                        {service.label}
                                    </Pill>
                                </td>
                                <td className="px-2 py-2">
                                    {service.latency === null
                                        ? '—'
                                        : `${service.latency} ms`}
                                </td>
                                <td className="px-2 py-2">
                                    {service.uptime === null
                                        ? '—'
                                        : `${service.uptime}%`}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    {ago(service.last_check)}
                                </td>
                                <td className="max-w-md px-2 py-2 text-slate-400">
                                    {service.latest_error ?? '—'}
                                </td>
                                <td className="px-2 py-2 text-[11px]">
                                    {service.depends
                                        .map((d) => names[d] ?? d)
                                        .join(', ') || '—'}
                                </td>
                                <td className="px-2 py-2">
                                    <Sparkline
                                        values={service.history}
                                        color={
                                            service.status === 'healthy'
                                                ? '#22c55e'
                                                : '#f59e0b'
                                        }
                                    />
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Panel>
                {selected !== '' && (
                    <Panel title={`Historial · ${names[selected] ?? selected}`}>
                        <Table
                            headers={['Fecha', 'Estado', 'Latencia', 'Detalle']}
                            empty={
                                history.length === 0 &&
                                'Sin chequeos registrados.'
                            }
                        >
                            {history.map((row, index) => (
                                <tr key={index}>
                                    <td className="px-2 py-1.5 whitespace-nowrap">
                                        {date(row.checked_at, true)}
                                    </td>
                                    <td className="px-2 py-1.5">
                                        <Pill tone={healthTone[row.status]}>
                                            {row.status}
                                        </Pill>
                                    </td>
                                    <td className="px-2 py-1.5">
                                        {row.latency_ms ?? '—'} ms
                                    </td>
                                    <td className="px-2 py-1.5 text-slate-400">
                                        {row.detail}
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
