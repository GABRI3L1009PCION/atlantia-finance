import { Head, router } from '@inertiajs/react';
import { Apple, Laptop, MonitorDown, WifiOff } from 'lucide-react';
import {
    Notice,
    PageHeader,
    Shell,
    badge,
    formatDate,
    panel,
    primaryButton,
    relativeTime,
    secondaryButton,
} from '@/components/office-admin/kit';

type Device = {
    id: number;
    name: string;
    platform: string;
    app_version: string | null;
    user: string;
    last_seen_at: string | null;
    last_snapshot_at: string | null;
    revoked_at: string | null;
    mine: boolean;
};

type Capture = {
    id: number;
    company: string;
    kind: string;
    kind_label: string;
    status: string;
    error: string | null;
    draft_url: string | null;
    captured_at: string;
    uploaded_at: string;
    user: string;
    summary: string;
};

const platforms: Record<string, string> = {
    windows: 'Windows',
    mac: 'Mac',
    linux: 'Linux',
};

export default function Desktop({
    devices,
    captures,
    canManageFirm,
    maxOfflineDays,
    downloads,
    notice,
}: {
    devices: Device[];
    captures: Capture[];
    canManageFirm: boolean;
    maxOfflineDays: number;
    downloads: { windows?: string; mac?: string };
    notice: string | null;
}) {
    return (
        <>
            <Head title="App de escritorio" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="App de escritorio"
                    subtitle="Sigue trabajando aunque se caiga el internet o el servidor"
                />

                <section
                    className={`${panel} grid gap-4 p-5 md:grid-cols-[1fr_auto]`}
                >
                    <div className="grid gap-2 text-sm text-[#4a5568]">
                        <p className="flex items-center gap-2 text-base font-bold text-[#061633]">
                            <WifiOff className="size-5 text-[#640000]" /> Cómo
                            funciona
                        </p>
                        <p>
                            Con conexión, la app muestra Atlantia Finance igual
                            que el navegador. En segundo plano guarda en tu
                            computadora una copia cifrada de tus empresas.
                        </p>
                        <p>
                            Si se cae el internet o el servidor, cambia sola al
                            modo sin conexión: consultas saldos, cuentas por
                            cobrar y por pagar, movimientos y obligaciones, y
                            capturas ventas, compras y asientos. Al volver la
                            conexión, las capturas se suben como borradores para
                            revisarlas aquí.
                        </p>
                        <p>
                            Por seguridad, la copia se bloquea si el equipo pasa
                            {` ${maxOfflineDays} `}días sin conectarse. Las
                            facturas FEL necesitan internet: sin conexión solo
                            se preparan.
                        </p>
                    </div>
                    <div className="flex flex-col gap-2 md:w-60">
                        {downloads.windows ? (
                            <a
                                className={primaryButton}
                                href={downloads.windows}
                            >
                                <MonitorDown className="size-4" /> Descargar
                                para Windows
                            </a>
                        ) : null}
                        {downloads.mac ? (
                            <a className={secondaryButton} href={downloads.mac}>
                                <Apple className="size-4" /> Descargar para Mac
                            </a>
                        ) : null}
                        {!downloads.windows && !downloads.mac && (
                            <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                                El instalador todavía no está publicado. Pídelo
                                a soporte de Atlantia.
                            </p>
                        )}
                    </div>
                </section>

                <section className={`${panel} mt-5 p-5`}>
                    <h2 className="text-lg font-bold text-[#061633]">
                        Capturas sin conexión
                    </h2>
                    <p className="text-sm text-[#526b92]">
                        Lo que se registró en la app sin conexión. Las que
                        quedaron como borrador se revisan y contabilizan como
                        cualquier otro; las que no pudieron crearse dicen por
                        qué.
                    </p>
                    {captures.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">
                            No hay capturas pendientes de revisar.
                        </p>
                    ) : (
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full min-w-[760px] text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-[#526b92] uppercase">
                                        <th className="px-3 py-2">Empresa</th>
                                        <th className="px-3 py-2">Tipo</th>
                                        <th className="px-3 py-2">Capturada</th>
                                        <th className="px-3 py-2">Resultado</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {captures.map((capture) => (
                                        <tr
                                            key={capture.id}
                                            className="border-t border-slate-100"
                                        >
                                            <td className="px-3 py-2 font-semibold text-[#061633]">
                                                {capture.company}
                                                <p className="text-xs font-normal text-[#526b92]">
                                                    {capture.summary}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2">
                                                {capture.kind_label}
                                            </td>
                                            <td className="px-3 py-2">
                                                {formatDate(
                                                    capture.captured_at,
                                                )}
                                                <p className="text-xs text-[#526b92]">
                                                    {capture.user}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2">
                                                {capture.status ===
                                                'draft_created' ? (
                                                    <span
                                                        className={badge(
                                                            'green',
                                                        )}
                                                    >
                                                        Borrador creado
                                                    </span>
                                                ) : (
                                                    <>
                                                        <span
                                                            className={badge(
                                                                'red',
                                                            )}
                                                        >
                                                            No se pudo crear
                                                        </span>
                                                        <p className="mt-1 text-xs text-red-700">
                                                            {capture.error}
                                                        </p>
                                                    </>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                                {capture.draft_url && (
                                                    <a
                                                        className="mr-3 text-xs font-bold text-[#640000] hover:underline"
                                                        href={capture.draft_url}
                                                    >
                                                        Abrir borrador
                                                    </a>
                                                )}
                                                <button
                                                    type="button"
                                                    className="text-xs font-bold text-[#526b92] hover:underline"
                                                    onClick={() =>
                                                        router.post(
                                                            `/desktop/captures/${capture.id}/dismiss`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Marcar revisada
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className={`${panel} mt-5 p-5`}>
                    <h2 className="text-lg font-bold text-[#061633]">
                        {canManageFirm
                            ? 'Equipos de la firma con la app'
                            : 'Tus equipos con la app'}
                    </h2>
                    <p className="text-sm text-[#526b92]">
                        Si pierdes o te roban una computadora, desconéctala: la
                        próxima vez que se conecte borrará su copia sin
                        conexión.
                    </p>
                    {devices.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">
                            Todavía no hay equipos con la app instalada.
                        </p>
                    ) : (
                        <ul className="mt-3 divide-y divide-slate-100">
                            {devices.map((device) => (
                                <li
                                    key={device.id}
                                    className="flex flex-wrap items-center gap-3 py-3"
                                >
                                    <Laptop className="size-5 text-[#640000]" />
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold text-[#061633]">
                                            {device.name} ·{' '}
                                            {platforms[device.platform] ??
                                                device.platform}
                                            {device.app_version &&
                                                ` · v${device.app_version}`}
                                        </p>
                                        <p className="text-xs text-[#526b92]">
                                            {device.user} · última conexión{' '}
                                            {device.last_seen_at
                                                ? relativeTime(
                                                      device.last_seen_at,
                                                  )
                                                : 'nunca'}{' '}
                                            · copia{' '}
                                            {device.last_snapshot_at
                                                ? relativeTime(
                                                      device.last_snapshot_at,
                                                  )
                                                : 'pendiente'}
                                        </p>
                                    </div>
                                    {device.revoked_at ? (
                                        <span className={badge('slate')}>
                                            Desconectado
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            onClick={() =>
                                                router.post(
                                                    `/desktop/devices/${device.id}/revoke`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Desconectar
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Shell>
        </>
    );
}
