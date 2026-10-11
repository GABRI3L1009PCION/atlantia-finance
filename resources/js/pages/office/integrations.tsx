import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    KeyRound,
    Lock,
    RefreshCw,
    Send,
    Webhook,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { textarea } from '@/components/auditor/kit';
import {
    Notice,
    PageHeader,
    Shell,
    formatDate,
    input,
    primaryButton,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, toneBadge } from '@/components/supervisor/kit';

type Ability = {
    code: string;
    label: string;
    group: string;
    permission: string | null;
    feature: string;
    grantable: boolean;
};
type Token = {
    id: number;
    name: string;
    prefix: string;
    owner: string;
    abilities: string[];
    companies: string[] | null;
    expires_at: string | null;
    last_used_at: string | null;
    last_used_ip: string | null;
    status: string;
    status_label: string;
    created_at: string;
};
type Endpoint = {
    id: number;
    name: string;
    url: string;
    events: string[];
    company_ids: number[] | null;
    companies: string[] | null;
    status: string;
    disabled_reason: string | null;
    consecutive_failures: number;
    last_success_at: string | null;
    last_failure_at: string | null;
    week: { succeeded: number; failed: number; pending: number };
};
type Delivery = {
    id: number;
    delivery_id: string;
    event: string;
    event_id: string;
    endpoint: string;
    company: string | null;
    status: string;
    attempts: number;
    response_status: number | null;
    response_body: string | null;
    error: string | null;
    payload: unknown;
    next_attempt_at: string | null;
    delivered_at: string | null;
    created_at: string;
};
type Usage = {
    days: { day: string; total: number; errors: number }[];
    total: number;
    errors: number;
    average_ms: number;
    recent: {
        id: number;
        token: string | null;
        method: string;
        path: string;
        status: number;
        duration_ms: number;
        ip: string | null;
        at: string;
    }[];
};
type EventOption = { code: string; label: string; group: string };
type Company = { id: number; public_id: string; name: string };

type Props = {
    tab: string;
    plan: { name: string; api: boolean; webhooks: boolean };
    can: { api: boolean; webhooks: boolean; logs: boolean };
    tokens: Token[];
    abilities: Ability[];
    companies: Company[];
    endpoints: Endpoint[];
    events: EventOption[];
    deliveries: Delivery[];
    filters: { endpoint_id?: number; status?: string; event?: string };
    usage: Usage | null;
    apiBaseUrl: string;
    reveal: { kind: string; title: string; value: string } | null;
    notice: string | null;
};

const tabs: Record<string, string> = {
    keys: 'Llaves de API',
    webhooks: 'Webhooks',
    deliveries: 'Entregas',
    usage: 'Uso de la API',
    docs: 'Documentación',
};

const deliveryTone: Record<string, string> = {
    succeeded: 'green',
    pending: 'amber',
    failed: 'red',
};

function groupBy<T extends { group: string }>(items: T[]): [string, T[]][] {
    const groups = new Map<string, T[]>();

    for (const item of items) {
        groups.set(item.group, [...(groups.get(item.group) ?? []), item]);
    }

    return [...groups.entries()];
}

function toggle(list: string[], value: string): string[] {
    return list.includes(value)
        ? list.filter((item) => item !== value)
        : [...list, value];
}

function CopyButton({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            className={secondaryButton}
            onClick={() => {
                void navigator.clipboard?.writeText(value);
                setCopied(true);
                window.setTimeout(() => setCopied(false), 2000);
            }}
        >
            {copied ? (
                <Check className="size-3.5" />
            ) : (
                <Copy className="size-3.5" />
            )}
            {copied ? 'Copiado' : 'Copiar'}
        </button>
    );
}

function Locked({ feature }: { feature: string }) {
    return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            <Lock className="size-4 shrink-0" />
            <span>
                {feature} no está incluido en tu plan actual. Está disponible en
                los planes Corporativo y Empresarial.
            </span>
            <Link href="/billing/plans" className={secondaryButton}>
                Ver planes
            </Link>
        </div>
    );
}

function Code({ children }: { children: ReactNode }) {
    return (
        <pre className="overflow-x-auto rounded-lg bg-[#0f172a] p-3 text-xs leading-relaxed text-slate-100">
            <code>{children}</code>
        </pre>
    );
}

export default function Integrations(props: Props) {
    const { tab, plan, reveal, notice } = props;

    return (
        <>
            <Head title="Integraciones" />
            <Shell>
                <PageHeader
                    title="Integraciones"
                    subtitle="Conecta Atlantia con tus sistemas: llaves de API para leer y registrar información, y webhooks que avisan cuando algo ocurre"
                />
                <Notice message={notice} />
                {reveal && (
                    <div className="rounded-xl border border-emerald-300 bg-emerald-50 p-4">
                        <p className="text-sm font-black text-emerald-900">
                            {reveal.title}
                        </p>
                        <p className="mt-1 text-xs text-emerald-800">
                            Cópialo ahora y guárdalo en un lugar seguro. Por
                            seguridad no se volverá a mostrar.
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <code className="rounded-lg bg-white px-3 py-2 font-mono text-sm break-all ring-1 ring-emerald-200">
                                {reveal.value}
                            </code>
                            <CopyButton value={reveal.value} />
                        </div>
                    </div>
                )}
                <nav className="flex flex-wrap gap-1">
                    {Object.entries(tabs).map(([key, label]) => (
                        <Link
                            key={key}
                            href={`/office/integrations?tab=${key}`}
                            preserveScroll
                            className={`rounded-full px-3 py-1.5 text-xs font-bold ${tab === key ? 'bg-[#640000] text-white' : 'bg-white text-[#172033] ring-1 ring-slate-200'}`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>
                {tab === 'keys' &&
                    (plan.api ? (
                        <KeysTab {...props} />
                    ) : (
                        <Locked feature="El acceso a la API" />
                    ))}
                {tab === 'webhooks' &&
                    (plan.webhooks ? (
                        <WebhooksTab {...props} />
                    ) : (
                        <Locked feature="Los webhooks" />
                    ))}
                {tab === 'deliveries' && <DeliveriesTab {...props} />}
                {tab === 'usage' && <UsageTab {...props} />}
                {tab === 'docs' && <DocsTab {...props} />}
            </Shell>
        </>
    );
}

function CompanyPicker({
    companies,
    value,
    onChange,
}: {
    companies: Company[];
    value: number[];
    onChange: (value: number[]) => void;
}) {
    return (
        <div className="grid gap-1 text-xs">
            <span className="font-semibold">Empresas</span>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={value.length === 0}
                    onChange={() => onChange([])}
                />
                Todas (las que el dueño de la llave puede ver)
            </label>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
                {companies.map((company) => (
                    <label key={company.id} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={value.includes(company.id)}
                            onChange={() =>
                                onChange(
                                    value.includes(company.id)
                                        ? value.filter(
                                              (id) => id !== company.id,
                                          )
                                        : [...value, company.id],
                                )
                            }
                        />
                        {company.name}
                    </label>
                ))}
            </div>
        </div>
    );
}

function KeysTab({ tokens, abilities, companies, can }: Props) {
    const form = useForm<{
        name: string;
        abilities: string[];
        company_ids: number[];
        expires_on: string;
    }>({ name: '', abilities: [], company_ids: [], expires_on: '' });
    const labels = Object.fromEntries(abilities.map((a) => [a.code, a.label]));

    return (
        <>
            {can.api && (
                <Card title="Nueva llave de API">
                    <form
                        className="grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) => ({
                                ...data,
                                expires_on: data.expires_on || null,
                            }));
                            form.post('/office/integrations/tokens', {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <div className="grid gap-2 md:grid-cols-3">
                            <input
                                className={input}
                                placeholder="Nombre (ej. Punto de venta tienda central)"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                            />
                            <label className="grid gap-1 text-xs font-semibold md:col-span-1">
                                Vence (opcional)
                                <input
                                    type="date"
                                    className={input}
                                    value={form.data.expires_on}
                                    onChange={(event) =>
                                        form.setData(
                                            'expires_on',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                            {groupBy(abilities).map(([group, items]) => (
                                <fieldset
                                    key={group}
                                    className="rounded-lg border border-slate-200 p-2"
                                >
                                    <legend className="px-1 text-xs font-black">
                                        {group}
                                    </legend>
                                    {items.map((ability) => (
                                        <label
                                            key={ability.code}
                                            className={`flex items-center gap-2 text-xs ${ability.grantable ? '' : 'text-slate-400'}`}
                                            title={
                                                ability.grantable
                                                    ? ability.code
                                                    : 'No tienes este permiso o tu plan no lo incluye'
                                            }
                                        >
                                            <input
                                                type="checkbox"
                                                disabled={!ability.grantable}
                                                checked={form.data.abilities.includes(
                                                    ability.code,
                                                )}
                                                onChange={() =>
                                                    form.setData(
                                                        'abilities',
                                                        toggle(
                                                            form.data.abilities,
                                                            ability.code,
                                                        ),
                                                    )
                                                }
                                            />
                                            {ability.label}
                                        </label>
                                    ))}
                                </fieldset>
                            ))}
                        </div>
                        <CompanyPicker
                            companies={companies}
                            value={form.data.company_ids}
                            onChange={(value) =>
                                form.setData('company_ids', value)
                            }
                        />
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-xs text-[#526b92]">
                                La llave actúa en tu nombre: nunca puede hacer
                                más de lo que tú puedes, y deja de funcionar si
                                pierdes el acceso. Las ventas y compras se
                                registran como borrador.
                            </p>
                            <button
                                type="submit"
                                className={primaryButton}
                                disabled={form.processing}
                            >
                                <KeyRound className="size-4" />
                                Crear llave
                            </button>
                        </div>
                    </form>
                </Card>
            )}
            <Card title={`${tokens.length} llave(s)`}>
                <Table
                    headers={[
                        'Llave',
                        'Dueño',
                        'Permisos',
                        'Empresas',
                        'Último uso',
                        'Estado',
                        '',
                    ]}
                    empty={tokens.length === 0 && 'Aún no hay llaves de API.'}
                >
                    {tokens.map((token) => (
                        <tr key={token.id}>
                            <td className="px-2 py-2">
                                <span className="block font-bold">
                                    {token.name}
                                </span>
                                <code className="text-[#526b92]">
                                    {token.prefix}_••••
                                </code>
                            </td>
                            <td className="px-2 py-2">{token.owner}</td>
                            <td className="max-w-80 px-2 py-2">
                                {token.abilities
                                    .map((code) => labels[code] ?? code)
                                    .join(', ')}
                            </td>
                            <td className="px-2 py-2">
                                {token.companies === null
                                    ? 'Todas'
                                    : token.companies.join(', ')}
                            </td>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(token.last_used_at, true)}
                                {token.last_used_ip && (
                                    <span className="block text-[#526b92]">
                                        {token.last_used_ip}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        token.status === 'active'
                                            ? 'green'
                                            : 'slate',
                                    )}
                                >
                                    {token.status_label}
                                </span>
                                {token.expires_at && (
                                    <span className="block text-[#526b92]">
                                        Vence {formatDate(token.expires_at)}
                                    </span>
                                )}
                            </td>
                            <td className="px-2 py-2">
                                {can.api && token.status === 'active' && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    `¿Revocar la llave "${token.name}"? Las integraciones que la usan dejarán de funcionar.`,
                                                )
                                            ) {
                                                router.post(
                                                    `/office/integrations/tokens/${token.id}/revoke`,
                                                    {},
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        Revocar
                                    </button>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </>
    );
}

function EndpointForm({
    events,
    companies,
    initial,
    onDone,
}: {
    events: EventOption[];
    companies: Company[];
    initial?: Endpoint;
    onDone?: () => void;
}) {
    const form = useForm<{
        name: string;
        url: string;
        events: string[];
        company_ids: number[];
    }>({
        name: initial?.name ?? '',
        url: initial?.url ?? '',
        events: initial?.events ?? [],
        company_ids: initial?.company_ids ?? [],
    });

    return (
        <form
            className="grid gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                const options = {
                    preserveScroll: true,
                    onSuccess: () => {
                        if (!initial) {
                            form.reset();
                        }

                        onDone?.();
                    },
                };

                if (initial) {
                    form.put(
                        `/office/integrations/webhooks/${initial.id}`,
                        options,
                    );
                } else {
                    form.post('/office/integrations/webhooks', options);
                }
            }}
        >
            <div className="grid gap-2 md:grid-cols-3">
                <input
                    className={input}
                    placeholder="Nombre (ej. ERP del cliente)"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    required
                />
                <input
                    className={`${input} md:col-span-2`}
                    type="url"
                    placeholder="https://tu-sistema.com/webhooks/atlantia"
                    value={form.data.url}
                    onChange={(event) =>
                        form.setData('url', event.target.value)
                    }
                    required
                />
            </div>
            {form.errors.url && (
                <p className="text-xs font-semibold text-red-700">
                    {form.errors.url}
                </p>
            )}
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                {groupBy(events).map(([group, items]) => (
                    <fieldset
                        key={group}
                        className="rounded-lg border border-slate-200 p-2"
                    >
                        <legend className="px-1 text-xs font-black">
                            {group}
                        </legend>
                        {items.map((item) => (
                            <label
                                key={item.code}
                                className="flex items-center gap-2 text-xs"
                                title={item.code}
                            >
                                <input
                                    type="checkbox"
                                    checked={form.data.events.includes(
                                        item.code,
                                    )}
                                    onChange={() =>
                                        form.setData(
                                            'events',
                                            toggle(form.data.events, item.code),
                                        )
                                    }
                                />
                                {item.label}
                            </label>
                        ))}
                    </fieldset>
                ))}
            </div>
            <CompanyPicker
                companies={companies}
                value={form.data.company_ids}
                onChange={(value) => form.setData('company_ids', value)}
            />
            <div className="flex justify-end gap-2">
                {onDone && initial && (
                    <button
                        type="button"
                        className={secondaryButton}
                        onClick={onDone}
                    >
                        Cancelar
                    </button>
                )}
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={form.processing}
                >
                    <Webhook className="size-4" />
                    {initial ? 'Guardar cambios' : 'Crear webhook'}
                </button>
            </div>
        </form>
    );
}

function WebhooksTab({ endpoints, events, companies, can }: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const labels = Object.fromEntries(events.map((e) => [e.code, e.label]));
    const action = (endpoint: Endpoint, name: string, ask?: string) => {
        if (ask && !window.confirm(ask)) {
            return;
        }

        router.post(
            `/office/integrations/webhooks/${endpoint.id}/${name}`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            {can.webhooks && (
                <Card title="Nuevo webhook">
                    <EndpointForm events={events} companies={companies} />
                </Card>
            )}
            <Card title={`${endpoints.length} webhook(s)`}>
                <Table
                    headers={[
                        'Webhook',
                        'Eventos',
                        'Empresas',
                        'Últimos 7 días',
                        'Estado',
                        '',
                    ]}
                    empty={endpoints.length === 0 && 'Aún no hay webhooks.'}
                >
                    {endpoints.map((endpoint) => (
                        <Fragment key={endpoint.id}>
                            <tr>
                                <td className="max-w-72 px-2 py-2">
                                    <span className="block font-bold">
                                        {endpoint.name}
                                    </span>
                                    <span className="block break-all text-[#526b92]">
                                        {endpoint.url}
                                    </span>
                                </td>
                                <td className="max-w-80 px-2 py-2">
                                    {endpoint.events
                                        .map((code) => labels[code] ?? code)
                                        .join(', ')}
                                </td>
                                <td className="px-2 py-2">
                                    {endpoint.companies === null
                                        ? 'Todas'
                                        : endpoint.companies.join(', ')}
                                </td>
                                <td className="px-2 py-2 whitespace-nowrap">
                                    <span className="text-emerald-700">
                                        {endpoint.week.succeeded} ok
                                    </span>
                                    {' · '}
                                    <span className="text-red-700">
                                        {endpoint.week.failed} fallidas
                                    </span>
                                    {endpoint.week.pending > 0 &&
                                        ` · ${endpoint.week.pending} en cola`}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            endpoint.status === 'active'
                                                ? 'green'
                                                : 'red',
                                        )}
                                    >
                                        {endpoint.status === 'active'
                                            ? 'Activo'
                                            : 'Deshabilitado'}
                                    </span>
                                    {endpoint.disabled_reason && (
                                        <span className="block text-red-700">
                                            {endpoint.disabled_reason}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    {can.webhooks && (
                                        <div className="flex flex-wrap gap-1">
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    action(endpoint, 'test')
                                                }
                                            >
                                                <Send className="size-3.5" />
                                                Probar
                                            </button>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    setEditing(
                                                        editing === endpoint.id
                                                            ? null
                                                            : endpoint.id,
                                                    )
                                                }
                                            >
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    action(
                                                        endpoint,
                                                        endpoint.status ===
                                                            'active'
                                                            ? 'disable'
                                                            : 'enable',
                                                    )
                                                }
                                            >
                                                {endpoint.status === 'active'
                                                    ? 'Deshabilitar'
                                                    : 'Activar'}
                                            </button>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    action(
                                                        endpoint,
                                                        'rotate',
                                                        '¿Generar un secreto nuevo? El actual dejará de ser válido de inmediato.',
                                                    )
                                                }
                                            >
                                                <RefreshCw className="size-3.5" />
                                                Rotar secreto
                                            </button>
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    action(
                                                        endpoint,
                                                        'delete',
                                                        `¿Eliminar el webhook "${endpoint.name}" y su historial de entregas?`,
                                                    )
                                                }
                                            >
                                                Eliminar
                                            </button>
                                        </div>
                                    )}
                                </td>
                            </tr>
                            {editing === endpoint.id && (
                                <tr>
                                    <td colSpan={6} className="bg-slate-50 p-3">
                                        <EndpointForm
                                            events={events}
                                            companies={companies}
                                            initial={endpoint}
                                            onDone={() => setEditing(null)}
                                        />
                                    </td>
                                </tr>
                            )}
                        </Fragment>
                    ))}
                </Table>
            </Card>
        </>
    );
}

function DeliveriesTab({ deliveries, endpoints, events, filters, can }: Props) {
    const [open, setOpen] = useState<number | null>(null);
    const filter = (key: string, value: string) =>
        router.get(
            '/office/integrations',
            {
                tab: 'deliveries',
                ...Object.fromEntries(
                    Object.entries({ ...filters, [key]: value }).filter(
                        ([, v]) => v !== '' && v !== undefined && v !== null,
                    ),
                ),
            },
            { preserveScroll: true },
        );

    return (
        <Card
            title="Entregas de webhooks (últimas 100)"
            action={
                <div className="flex flex-wrap gap-2">
                    <select
                        aria-label="Webhook"
                        className={`${input} h-8 w-auto`}
                        value={filters.endpoint_id ?? ''}
                        onChange={(event) =>
                            filter('endpoint_id', event.target.value)
                        }
                    >
                        <option value="">Todos los webhooks</option>
                        {endpoints.map((endpoint) => (
                            <option key={endpoint.id} value={endpoint.id}>
                                {endpoint.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Estado"
                        className={`${input} h-8 w-auto`}
                        value={filters.status ?? ''}
                        onChange={(event) =>
                            filter('status', event.target.value)
                        }
                    >
                        <option value="">Todos los estados</option>
                        <option value="succeeded">Entregadas</option>
                        <option value="pending">
                            En cola o por reintentar
                        </option>
                        <option value="failed">Fallidas</option>
                    </select>
                    <select
                        aria-label="Evento"
                        className={`${input} h-8 w-auto`}
                        value={filters.event ?? ''}
                        onChange={(event) =>
                            filter('event', event.target.value)
                        }
                    >
                        <option value="">Todos los eventos</option>
                        <option value="webhook.ping">Prueba (ping)</option>
                        {events.map((item) => (
                            <option key={item.code} value={item.code}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                </div>
            }
        >
            <Table
                headers={[
                    'Fecha',
                    'Evento',
                    'Webhook',
                    'Empresa',
                    'Intentos',
                    'Respuesta',
                    'Estado',
                    '',
                ]}
                empty={deliveries.length === 0 && 'Sin entregas registradas.'}
            >
                {deliveries.map((delivery) => (
                    <Fragment key={delivery.id}>
                        <tr>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(delivery.created_at, true)}
                            </td>
                            <td className="px-2 py-2 font-mono">
                                {delivery.event}
                            </td>
                            <td className="px-2 py-2">{delivery.endpoint}</td>
                            <td className="px-2 py-2">
                                {delivery.company ?? '—'}
                            </td>
                            <td className="px-2 py-2">{delivery.attempts}</td>
                            <td className="px-2 py-2">
                                {delivery.response_status ?? '—'}
                                {delivery.error && (
                                    <span className="block max-w-64 text-red-700">
                                        {delivery.error}
                                    </span>
                                )}
                                {delivery.status === 'pending' &&
                                    delivery.next_attempt_at && (
                                        <span className="block text-[#526b92]">
                                            Próximo intento{' '}
                                            {formatDate(
                                                delivery.next_attempt_at,
                                                true,
                                            )}
                                        </span>
                                    )}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        deliveryTone[delivery.status] ??
                                            'slate',
                                    )}
                                >
                                    {
                                        {
                                            succeeded: 'Entregada',
                                            pending: 'En cola',
                                            failed: 'Fallida',
                                        }[delivery.status]
                                    }
                                </span>
                            </td>
                            <td className="px-2 py-2">
                                <div className="flex gap-1">
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() =>
                                            setOpen(
                                                open === delivery.id
                                                    ? null
                                                    : delivery.id,
                                            )
                                        }
                                    >
                                        Detalle
                                    </button>
                                    {can.webhooks &&
                                        delivery.status !== 'succeeded' && (
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() =>
                                                    router.post(
                                                        `/office/integrations/deliveries/${delivery.id}/retry`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Reenviar
                                            </button>
                                        )}
                                </div>
                            </td>
                        </tr>
                        {open === delivery.id && (
                            <tr>
                                <td colSpan={8} className="bg-slate-50 p-3">
                                    <div className="grid gap-3 lg:grid-cols-2">
                                        <div>
                                            <p className="mb-1 text-xs font-bold">
                                                Contenido enviado · entrega{' '}
                                                {delivery.delivery_id}
                                            </p>
                                            <Code>
                                                {JSON.stringify(
                                                    delivery.payload,
                                                    null,
                                                    2,
                                                )}
                                            </Code>
                                        </div>
                                        <div>
                                            <p className="mb-1 text-xs font-bold">
                                                Respuesta del receptor
                                            </p>
                                            <textarea
                                                readOnly
                                                className={`${textarea} min-h-40 font-mono text-xs`}
                                                value={
                                                    delivery.response_body ??
                                                    delivery.error ??
                                                    ''
                                                }
                                            />
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        )}
                    </Fragment>
                ))}
            </Table>
        </Card>
    );
}

function UsageTab({ usage }: Props) {
    if (!usage) {
        return (
            <Card title="Uso de la API">
                <p className="py-6 text-center text-sm text-slate-500">
                    No tienes permiso para ver el uso de la API.
                </p>
            </Card>
        );
    }

    const max = Math.max(1, ...usage.days.map((day) => day.total));

    return (
        <>
            <section className="grid gap-2 sm:grid-cols-3">
                {[
                    ['Llamadas (7 días)', usage.total.toLocaleString('es-GT')],
                    ['Con error', usage.errors.toLocaleString('es-GT')],
                    ['Tiempo promedio', `${usage.average_ms} ms`],
                ].map(([label, value]) => (
                    <div
                        key={label}
                        className="rounded-xl border border-[#e7e1dd] bg-white p-3 shadow-sm"
                    >
                        <p className="text-xs text-[#526b92]">{label}</p>
                        <p className="text-2xl font-black text-[#061633]">
                            {value}
                        </p>
                    </div>
                ))}
            </section>
            <Card title="Llamadas por día">
                <div className="flex h-36 items-end gap-2">
                    {usage.days.map((day) => (
                        <div
                            key={day.day}
                            className="flex flex-1 flex-col items-center gap-1"
                        >
                            <span className="text-[10px] font-bold">
                                {day.total}
                            </span>
                            <div
                                className="flex w-full flex-col justify-end overflow-hidden rounded bg-slate-100"
                                style={{ height: '100px' }}
                            >
                                <div
                                    className="w-full bg-[#640000]"
                                    style={{
                                        height: `${(day.total / max) * 100}%`,
                                    }}
                                />
                            </div>
                            <span className="text-[10px] text-[#526b92]">
                                {formatDate(day.day).slice(0, 5)}
                            </span>
                        </div>
                    ))}
                </div>
            </Card>
            <Card title="Últimas 50 llamadas">
                <Table
                    headers={[
                        'Fecha',
                        'Llave',
                        'Solicitud',
                        'Estado',
                        'Tiempo',
                        'IP',
                    ]}
                    empty={usage.recent.length === 0 && 'Aún no hay llamadas.'}
                >
                    {usage.recent.map((row) => (
                        <tr key={row.id}>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(row.at, true)}
                            </td>
                            <td className="px-2 py-2">{row.token ?? '—'}</td>
                            <td className="px-2 py-2 font-mono">
                                {row.method} {row.path}
                            </td>
                            <td className="px-2 py-2">
                                <span
                                    className={toneBadge(
                                        row.status < 400
                                            ? 'green'
                                            : row.status === 429
                                              ? 'amber'
                                              : 'red',
                                    )}
                                >
                                    {row.status}
                                </span>
                            </td>
                            <td className="px-2 py-2">{row.duration_ms} ms</td>
                            <td className="px-2 py-2">{row.ip ?? '—'}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </>
    );
}

const endpointsDoc: [string, string, string, string][] = [
    ['GET', '/me', '—', 'Datos de la llave, organización y permisos.'],
    ['GET', '/companies', 'companies:read', 'Empresas que la llave puede ver.'],
    ['GET', '/companies/{empresa}', 'companies:read', 'Una empresa.'],
    [
        'GET',
        '/companies/{empresa}/customers',
        'customers:read',
        'Clientes. Filtros: search, tax_id, updated_since.',
    ],
    [
        'POST',
        '/companies/{empresa}/customers',
        'customers:write',
        'Crea un cliente: name, tax_id, email, payment_terms_days.',
    ],
    [
        'PATCH',
        '/companies/{empresa}/customers/{id}',
        'customers:write',
        'Actualiza un cliente.',
    ],
    [
        'GET',
        '/companies/{empresa}/suppliers',
        'suppliers:read',
        'Proveedores. Filtros: search, tax_id, updated_since.',
    ],
    [
        'POST',
        '/companies/{empresa}/suppliers',
        'suppliers:write',
        'Crea un proveedor.',
    ],
    [
        'PATCH',
        '/companies/{empresa}/suppliers/{id}',
        'suppliers:write',
        'Actualiza un proveedor.',
    ],
    [
        'GET',
        '/companies/{empresa}/sales-invoices',
        'sales:read',
        'Ventas. Filtros: status, customer_id, from, to, updated_since.',
    ],
    [
        'GET',
        '/companies/{empresa}/sales-invoices/{id}',
        'sales:read',
        'Una venta con sus líneas, cobros y saldo.',
    ],
    [
        'POST',
        '/companies/{empresa}/sales-invoices',
        'sales:write',
        'Registra una venta en borrador (idempotente con request_id).',
    ],
    [
        'GET',
        '/companies/{empresa}/purchase-documents',
        'purchases:read',
        'Compras y gastos. Filtros: status, document_type, supplier_id, from, to.',
    ],
    [
        'GET',
        '/companies/{empresa}/purchase-documents/{id}',
        'purchases:read',
        'Una compra con sus líneas y saldo.',
    ],
    [
        'POST',
        '/companies/{empresa}/purchase-documents',
        'purchases:write',
        'Registra una compra o gasto en borrador (idempotente).',
    ],
    [
        'GET',
        '/companies/{empresa}/receivables',
        'receivables:read',
        'Cuentas por cobrar abiertas. status: open, overdue, upcoming, all.',
    ],
    [
        'GET',
        '/companies/{empresa}/payables',
        'payables:read',
        'Cuentas por pagar abiertas.',
    ],
    [
        'GET',
        '/companies/{empresa}/accounts',
        'accounts:read',
        'Catálogo de cuentas. Filtros: type, accepts_movements.',
    ],
    [
        'GET',
        '/companies/{empresa}/reports/trial-balance',
        'reports:read',
        'Balance de comprobación (from, to).',
    ],
    [
        'GET',
        '/companies/{empresa}/reports/income-statement',
        'reports:read',
        'Estado de resultados (from, to).',
    ],
    [
        'GET',
        '/companies/{empresa}/reports/balance-sheet',
        'reports:read',
        'Balance general (from, to).',
    ],
    [
        'GET',
        '/companies/{empresa}/fel-documents',
        'fel:read',
        'Documentos FEL certificados o anulados.',
    ],
];

function DocsTab({ apiBaseUrl, events }: Props) {
    const sale = `curl -X POST ${apiBaseUrl}/companies/{empresa}/sales-invoices \\
  -H "Authorization: Bearer atl_xxxxxxxx_..." \\
  -H "Content-Type: application/json" -H "Accept: application/json" \\
  -d '{
    "request_id": "6f1c2b9e-3a4d-4e1f-9b7a-2c3d4e5f6a7b",
    "customer_id": 12,
    "issued_on": "2026-09-30",
    "lines": [
      { "description": "Servicio mensual", "quantity": 1, "unit_price": "1120.00", "tax_treatment": "services" }
    ]
  }'`;
    const verifyPhp = `$payload = file_get_contents('php://input');
[$t, $v1] = sscanf($_SERVER['HTTP_ATLANTIA_SIGNATURE'], 't=%d,v1=%s');
$expected = hash_hmac('sha256', $t.'.'.$payload, getenv('ATLANTIA_WEBHOOK_SECRET'));
if (! hash_equals($expected, $v1) || abs(time() - $t) > 300) {
    http_response_code(400); exit;
}
$event = json_decode($payload, true); // $event['type'], $event['data']
http_response_code(200);`;
    const verifyNode = `import crypto from 'node:crypto';
// Usa el cuerpo crudo (raw body), no el JSON ya interpretado.
const [t, v1] = req.headers['atlantia-signature'].split(',').map((p) => p.split('=')[1]);
const expected = crypto.createHmac('sha256', process.env.ATLANTIA_WEBHOOK_SECRET)
  .update(\`\${t}.\${rawBody}\`).digest('hex');
const valid = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(v1))
  && Math.abs(Date.now() / 1000 - Number(t)) < 300;`;

    return (
        <div className="grid gap-3">
            <Card title="Cómo empezar">
                <ol className="grid list-decimal gap-1 pl-5 text-sm">
                    <li>
                        Crea una llave en «Llaves de API» con los permisos
                        mínimos que necesita la integración y, si aplica,
                        limitada a ciertas empresas.
                    </li>
                    <li>
                        Envía la llave en cada solicitud con el encabezado{' '}
                        <code>Authorization: Bearer atl_…</code> y{' '}
                        <code>Accept: application/json</code>.
                    </li>
                    <li>
                        La dirección base es <code>{apiBaseUrl}</code>. Las
                        empresas se identifican con su <code>id</code> (UUID) de{' '}
                        <code>GET /companies</code>.
                    </li>
                    <li>
                        Los montos van en centavos de quetzal (
                        <code>total_cents: 112000</code> = Q1,120.00). Al
                        registrar, los precios se envían como texto decimal (
                        <code>"1120.00"</code>).
                    </li>
                    <li>
                        Las ventas y compras se registran en borrador: la
                        emisión, la certificación FEL y la contabilización las
                        hace la oficina con sus aprobaciones.
                    </li>
                </ol>
            </Card>
            <Card title="Endpoints">
                <Table headers={['Método', 'Ruta', 'Permiso', 'Descripción']}>
                    {endpointsDoc.map(
                        ([method, path, ability, description]) => (
                            <tr key={method + path}>
                                <td className="px-2 py-2 font-mono font-bold">
                                    {method}
                                </td>
                                <td className="px-2 py-2 font-mono">{path}</td>
                                <td className="px-2 py-2 font-mono">
                                    {ability}
                                </td>
                                <td className="px-2 py-2">{description}</td>
                            </tr>
                        ),
                    )}
                </Table>
            </Card>
            <div className="grid gap-3 xl:grid-cols-2">
                <Card title="Ejemplo: registrar una venta">
                    <Code>{sale}</Code>
                    <p className="mt-2 text-xs text-[#526b92]">
                        Repetir la misma solicitud con el mismo{' '}
                        <code>request_id</code> devuelve la misma venta (200) en
                        lugar de duplicarla. La primera vez responde 201.
                    </p>
                </Card>
                <Card title="Respuestas, paginación y errores">
                    <ul className="grid gap-1 text-sm">
                        <li>
                            Listas:{' '}
                            <code>
                                {
                                    '{ data: [...], meta: { page, per_page, total, has_more } }'
                                }
                            </code>{' '}
                            con <code>?page=</code> y <code>?per_page=</code>{' '}
                            (máx. 100).
                        </li>
                        <li>
                            Errores:{' '}
                            <code>{'{ error: { code, message } }'}</code>. 401
                            llave inválida, revocada o vencida · 403 sin permiso
                            o fuera del plan · 404 no existe o sin acceso · 422
                            datos inválidos (<code>errors</code> por campo) ·
                            429 demasiadas solicitudes.
                        </li>
                        <li>
                            Límite: 120 solicitudes por minuto por llave
                            (encabezados <code>X-RateLimit-*</code>).
                        </li>
                        <li>
                            Cada llamada queda registrada en «Uso de la API» y
                            cada cambio en la auditoría con el origen «api».
                        </li>
                    </ul>
                </Card>
            </div>
            <Card title="Webhooks">
                <div className="grid gap-3 xl:grid-cols-2">
                    <div className="grid gap-2 text-sm">
                        <p>
                            Atlantia envía un <code>POST</code> JSON a tu URL
                            (https) cuando ocurre un evento al que te
                            suscribiste:
                        </p>
                        <Code>{`{
  "id": "evento-uuid",
  "type": "sales_invoice.issued",
  "created_at": "2026-09-30T10:00:00-06:00",
  "api_version": "v1",
  "company": { "id": "...", "name": "...", "tax_id": "..." },
  "data": { ...mismo formato que la API... }
}`}</Code>
                        <p>
                            Encabezados: <code>Atlantia-Event</code>,{' '}
                            <code>Atlantia-Delivery</code> (id único de la
                            entrega) y <code>Atlantia-Signature</code>.
                        </p>
                        <p>
                            Responde con un código 2xx en menos de 10 segundos.
                            Si falla, se reintenta a 1 min, 5 min, 30 min, 2 h,
                            6 h y 12 h; tras 15 fallas seguidas el webhook se
                            deshabilita y se te notifica. Usa el <code>id</code>{' '}
                            del evento para ignorar duplicados.
                        </p>
                    </div>
                    <div className="grid gap-2">
                        <p className="text-sm font-bold">
                            Verificar la firma (PHP)
                        </p>
                        <Code>{verifyPhp}</Code>
                        <p className="text-sm font-bold">Node.js</p>
                        <Code>{verifyNode}</Code>
                    </div>
                </div>
                <p className="mt-3 text-xs font-bold">Eventos disponibles</p>
                <div className="mt-1 grid gap-1 text-xs sm:grid-cols-2 xl:grid-cols-3">
                    {events.map((item) => (
                        <span key={item.code}>
                            <code className="font-bold">{item.code}</code> ·{' '}
                            {item.label}
                        </span>
                    ))}
                    <span>
                        <code className="font-bold">webhook.ping</code> · Prueba
                        enviada con el botón «Probar»
                    </span>
                </div>
            </Card>
        </div>
    );
}
