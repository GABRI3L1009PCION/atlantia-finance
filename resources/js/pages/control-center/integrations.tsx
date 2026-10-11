import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    CCPage,
    Panel,
    Pill,
    SensitiveAction,
    ago,
    ccInput,
    date,
    panel,
    statusTone,
} from '@/components/control-center/kit';

type Integration = {
    key: string;
    name: string;
    category: string;
    enabled: boolean;
    status: string;
    status_label: string;
    config: Record<string, string>;
    has_secret: boolean;
    secret_hint: string | null;
    secret_source: string | null;
    secret_rotated_at: string | null;
    last_check: string | null;
    detail: string | null;
};

function SecretForm({ integration }: { integration: Integration }) {
    const [secret, setSecret] = useState('');

    return (
        <div className="mt-2 flex flex-wrap items-center gap-2">
            <input
                type="password"
                autoComplete="off"
                className={`${ccInput} h-8 max-w-xs`}
                placeholder="Nuevo secreto"
                value={secret}
                onChange={(e) => setSecret(e.target.value)}
            />
            {secret.length >= 8 && (
                <SensitiveAction
                    url={`/control-center/integrations/${integration.key}/secret`}
                    data={{ secret }}
                    label="Guardar secreto"
                    title={`Actualizar secreto de ${integration.name}`}
                    description="Se guarda cifrado y nunca se volverá a mostrar completo. El cambio queda auditado sin el valor."
                />
            )}
        </div>
    );
}

export default function Integrations({
    integrations,
    tenant_integrations,
    can_manage,
}: {
    integrations: Integration[];
    tenant_integrations: Record<string, number>;
    can_manage: boolean;
}) {
    return (
        <>
            <Head title="Integraciones · Control Center" />
            <CCPage
                title="Integraciones"
                subtitle="Proveedores de la plataforma: pagos, correo, almacenamiento, FEL y Luzma. Los secretos nunca se muestran completos (sk_••••••••ABCD)."
            >
                <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    {[
                        [
                            'Llaves de API de clientes',
                            tenant_integrations.api_tokens,
                        ],
                        ['Webhooks activos', tenant_integrations.webhooks],
                        [
                            'Webhooks deshabilitados',
                            tenant_integrations.webhooks_disabled,
                        ],
                        [
                            'Entregas fallidas 24 h',
                            tenant_integrations.deliveries_failed_24h,
                        ],
                        [
                            'Solicitudes API 24 h',
                            tenant_integrations.api_requests_24h,
                        ],
                        [
                            'Errores 5xx API 24 h',
                            tenant_integrations.api_errors_24h,
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
                <div className="grid gap-3 xl:grid-cols-2">
                    {integrations.map((integration) => (
                        <Panel
                            key={integration.key}
                            title={integration.name}
                            action={
                                <Pill
                                    tone={
                                        statusTone[
                                            integration.status === 'active'
                                                ? 'active'
                                                : integration.status
                                        ]
                                    }
                                >
                                    {integration.status_label}
                                </Pill>
                            }
                        >
                            <dl className="grid gap-1 text-xs">
                                {Object.entries(integration.config).map(
                                    ([key, value]) => (
                                        <div
                                            key={key}
                                            className="flex justify-between gap-2"
                                        >
                                            <dt className="text-slate-500">
                                                {key}
                                            </dt>
                                            <dd className="truncate font-mono text-slate-300">
                                                {value}
                                            </dd>
                                        </div>
                                    ),
                                )}
                                {integration.has_secret && (
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-slate-500">
                                            Secreto
                                        </dt>
                                        <dd className="font-mono text-slate-300">
                                            {integration.secret_hint ??
                                                'No configurado'}{' '}
                                            {integration.secret_source && (
                                                <span className="text-slate-500">
                                                    (
                                                    {integration.secret_source ===
                                                    'control_center'
                                                        ? 'Control Center'
                                                        : 'entorno'}
                                                    )
                                                </span>
                                            )}
                                        </dd>
                                    </div>
                                )}
                                {integration.secret_rotated_at && (
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">
                                            Rotado
                                        </dt>
                                        <dd>
                                            {date(
                                                integration.secret_rotated_at,
                                                true,
                                            )}
                                        </dd>
                                    </div>
                                )}
                                <div className="flex justify-between">
                                    <dt className="text-slate-500">
                                        Último chequeo
                                    </dt>
                                    <dd>{ago(integration.last_check)}</dd>
                                </div>
                            </dl>
                            {integration.detail && (
                                <p className="mt-2 text-xs text-[#fbbf24]">
                                    {integration.detail}
                                </p>
                            )}
                            {can_manage && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <SensitiveAction
                                        danger={integration.enabled}
                                        url={`/control-center/integrations/${integration.key}/${integration.enabled ? 'disable' : 'enable'}`}
                                        label={
                                            integration.enabled
                                                ? 'Deshabilitar'
                                                : 'Habilitar'
                                        }
                                        title={`${integration.enabled ? 'Deshabilitar' : 'Habilitar'} ${integration.name}`}
                                    />
                                </div>
                            )}
                            {can_manage && integration.has_secret && (
                                <SecretForm integration={integration} />
                            )}
                        </Panel>
                    ))}
                </div>
            </CCPage>
        </>
    );
}
