import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    CheckCircle2,
    Clock3,
    Copy,
    X,
    KeyRound,
    Layers,
    Plug,
    RefreshCw,
    ShieldCheck,
    Webhook,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type Connected = { name: string; category: string; description: string; status: 'connected' | 'idle'; detail: string; last_sync: string | null; manage: 'keys' | 'webhooks' | 'fel' | null; companies?: { public_id: string; name: string; configured: boolean }[] };

type Field = { key: string; label: string; type: string; secret?: boolean; required?: boolean; placeholder?: string; options?: string[] };
type Provider = {
    key: string;
    label: string;
    category: string;
    description: string;
    fields: Field[];
    connected: boolean;
    status: string | null;
    last_synced_at: string | null;
    last_error: string | null;
    values: Record<string, string | boolean>;
    calendar_url: string | null;
    builtin: boolean;
    oauth: boolean;
    authorized: boolean;
    redirect_uri: string | null;
    console: string | null;
};
type ConnectionEvent = { provider: string; event: string; ok: boolean; message: string | null; at: string };

type Props = {
    providers: Provider[];
    connection_events: ConnectionEvent[];
    kpis: { connected: number; available: number; automations: number; syncs_month: number; availability: number | null };
    connected: Connected[];
    coming_soon: { name: string; category: string; description: string }[];
    tokens: { name: string; prefix: string; abilities: string[]; created_at: string; owner: string; status: string; status_label: string; expires_at: string | null }[];
    endpoints: { name: string; url: string; events: string[]; status: string; failures: number }[];
    history: { at: string; integration: string; event: string; ok: boolean; pending: boolean }[];
    canManage: boolean;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const CATEGORY_STYLE: Record<string, string> = {
    Fiscal: 'bg-slate-100 text-slate-600',
    Comunicación: 'bg-blue-50 text-blue-600',
    Almacenamiento: 'bg-indigo-50 text-indigo-600',
    Financiero: 'bg-emerald-50 text-emerald-700',
    Productividad: 'bg-violet-50 text-violet-600',
    CRM: 'bg-orange-50 text-orange-600',
    Desarrollo: 'bg-slate-100 text-slate-600',
    Automatización: 'bg-amber-50 text-amber-700',
    Inteligencia: 'bg-rose-50 text-rose-700',
};
const ICON: Record<string, ReactNode> = {
    'FEL / SAT': <ShieldCheck className="size-9" />,
    'API pública': <KeyRound className="size-9" />,
    Webhooks: <Webhook className="size-9" />,
    'Luzma IA': <Activity className="size-9" />,
    'Pagos (Recurrente)': <Layers className="size-9" />,
};

function formatWhen(value: string | null): string {
    return value ? new Date(value).toLocaleString('es-GT', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
}

function Kpi({ icon, tone, value, label, hint }: { icon: ReactNode; tone: string; value: string; label: string; hint: string }) {
    return (
        <div className={`${card} flex items-center gap-4`}>
            <span className={`grid size-16 shrink-0 place-items-center rounded-2xl ${tone}`}>{icon}</span>
            <div className="min-w-0"><p className="text-3xl font-black text-[#061633]">{value}</p><p className="truncate text-sm font-bold">{label}</p><p className="truncate text-xs text-slate-500">{hint}</p></div>
        </div>
    );
}

function ConnectDialog({ provider, onClose }: { provider: Provider; onClose: () => void }) {
    const form = useForm<Record<string, string | boolean>>(
        Object.fromEntries(provider.fields.map((field) => [field.key, provider.values[field.key] ?? (field.type === 'checkbox' ? true : field.options ? field.options[0] : '')])),
    );
    const submit = (event: { preventDefault: () => void }) => {
        event.preventDefault();
        form.post(`/office/integrations/connections/${provider.key}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-[90] grid place-items-center bg-[#071a38]/50 p-4" role="dialog" aria-modal="true">
            <form onSubmit={submit} className="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-black">{provider.connected || provider.builtin || provider.status === 'pending' ? 'Configurar' : 'Conectar'} {provider.label}</h2>
                        <p className="text-sm text-slate-600">{provider.description}</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="size-5" /></button>
                </div>
                {provider.fields.length === 0 && !provider.oauth && (
                    <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">No necesitas datos: al conectar se genera un enlace privado que agregas a Google Calendar u Outlook (Agregar calendario, Desde URL).</p>
                )}
                {provider.oauth && (
                    <div className="space-y-1 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                        <p><b>1.</b> Crea una app en: {provider.console}.</p>
                        <p><b>2.</b> Registra esta dirección de redirección:</p>
                        <div className="flex items-center gap-2 rounded-lg bg-white p-2"><code className="min-w-0 flex-1 break-all text-[11px]">{provider.redirect_uri}</code><button type="button" aria-label="Copiar dirección" onClick={() => navigator.clipboard?.writeText(provider.redirect_uri ?? '')} className="rounded p-1 hover:bg-slate-100"><Copy className="size-4" /></button></div>
                        <p><b>3.</b> Pega abajo el ID y el secreto, guarda y pulsa <b>Autorizar</b>.</p>
                    </div>
                )}
                {provider.fields.map((field) => (
                    <label key={field.key} className={field.type === 'checkbox' ? 'flex items-center gap-2 text-sm font-semibold' : 'block text-sm font-bold'}>
                        {field.type === 'checkbox' ? (
                            <>
                                <input type="checkbox" className="size-5 accent-[#8a0b0b]" checked={Boolean(form.data[field.key])} onChange={(e) => form.setData(field.key, e.target.checked)} />
                                {field.label}
                            </>
                        ) : (
                            <>
                                <span className="mb-1.5 block">{field.label} {field.required && <span className="text-red-600">*</span>}</span>
                                {field.type === 'select' ? (
                                    <select className="h-11 w-full rounded-xl border border-[#e4dbd6] px-3 text-sm" value={String(form.data[field.key])} onChange={(e) => form.setData(field.key, e.target.value)}>
                                        {field.options?.map((option) => <option key={option}>{option}</option>)}
                                    </select>
                                ) : (
                                    <input className="h-11 w-full rounded-xl border border-[#e4dbd6] px-3 text-sm outline-none focus:border-[#8a0b0b]" type={field.type === 'url' ? 'text' : field.type} placeholder={field.placeholder} autoComplete="off" value={String(form.data[field.key] ?? '')} onChange={(e) => form.setData(field.key, e.target.value)} />
                                )}
                                {form.errors[field.key] && <span className="mt-1 block text-xs font-semibold text-red-600">{form.errors[field.key]}</span>}
                            </>
                        )}
                    </label>
                ))}
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onClose} className="h-11 rounded-xl border border-[#e4dbd6] px-5 text-sm font-bold">Cancelar</button>
                    <button className="h-11 rounded-xl bg-[#8a0b0b] px-5 text-sm font-bold text-white disabled:opacity-60" disabled={form.processing}>{provider.connected || provider.builtin || provider.status === 'pending' ? 'Guardar' : 'Conectar'}</button>
                </div>
            </form>
        </div>
    );
}

function ProviderCard({ provider, canManage, onConfigure }: { provider: Provider; canManage: boolean; onConfigure: () => void }) {
    const act = (url: string, method: 'post' | 'delete' = 'post') => router[method](url, {}, { preserveScroll: true });
    const pending = provider.status === 'pending';
    const tone = provider.status === 'error' ? 'text-red-600' : provider.connected ? 'text-emerald-600' : pending ? 'text-amber-600' : 'text-slate-400';
    const dot = provider.status === 'error' ? 'bg-red-500' : provider.connected ? 'bg-emerald-500' : pending ? 'bg-amber-500' : 'bg-slate-300';
    const configured = provider.connected || pending || provider.builtin;

    return (
        <article className="rounded-xl border border-slate-200 p-4">
            <div className="flex items-start gap-3">
                <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]"><Plug className="size-8" /></span>
                <div className="min-w-0">
                    <p className="font-black">{provider.label}</p>
                    <span className={`rounded-md px-2 py-0.5 text-[11px] font-bold ${CATEGORY_STYLE[provider.category] ?? 'bg-slate-100'}`}>{provider.category}</span>
                </div>
            </div>
            <p className="mt-2 text-xs text-slate-600">{provider.description}</p>
            <div className="mt-3 flex items-center justify-between">
                <span className={`flex items-center gap-1.5 text-sm font-bold ${tone}`}><i className={`size-2.5 rounded-full ${dot}`} />{provider.status === 'error' ? 'Con error' : provider.builtin ? (provider.connected ? 'Activa' : 'Desactivada') : provider.connected ? 'Conectada' : pending ? 'Falta autorizar' : 'Desconectada'}</span>
                {canManage && (
                    <button type="button" onClick={onConfigure} className={configured ? 'rounded-lg border border-[#e4dbd6] px-3 py-1.5 text-xs font-bold' : 'rounded-lg bg-[#8a0b0b] px-3 py-1.5 text-xs font-bold text-white'}>
                        {configured ? 'Configurar' : 'Conectar'}
                    </button>
                )}
            </div>
            {provider.last_error && <p className="mt-2 rounded-lg bg-red-50 p-2 text-[11px] text-red-700">{provider.last_error}</p>}
            {provider.calendar_url && (
                <div className="mt-2 flex items-center gap-2 rounded-lg bg-slate-50 p-2">
                    <code className="min-w-0 flex-1 truncate text-[11px]">{provider.calendar_url}</code>
                    <button type="button" aria-label="Copiar enlace" onClick={() => navigator.clipboard?.writeText(provider.calendar_url ?? '')} className="rounded p-1 hover:bg-white"><Copy className="size-4" /></button>
                </div>
            )}
            <dl className="mt-3 grid grid-cols-2 gap-2 border-t border-slate-100 pt-2 text-[11px]">
                <div><dt className="text-slate-400">Última actividad</dt><dd className="font-bold">{formatWhen(provider.last_synced_at)}</dd></div>
            </dl>
            {canManage && provider.oauth && (provider.connected || pending) && (
                <div className="mt-2"><a href={`/office/integrations/oauth/${provider.key}/redirect`} className={provider.authorized ? 'inline-block rounded-lg border border-[#e4dbd6] px-2.5 py-1 text-xs font-bold' : 'inline-block rounded-lg bg-[#8a0b0b] px-2.5 py-1 text-xs font-bold text-white'}>{provider.authorized ? 'Volver a autorizar' : 'Autorizar'}</a></div>
            )}
            {canManage && (provider.connected || pending) && !provider.builtin && (
                <div className="mt-2 flex flex-wrap gap-2">
                    {(provider.key === 'google_drive' || provider.key === 'dropbox') && provider.connected && <button type="button" onClick={() => act(`/office/integrations/connections/${provider.key}/backup`)} className="rounded-lg border border-[#e4dbd6] px-2.5 py-1 text-xs font-bold">Respaldar ahora</button>}
                    <button type="button" onClick={() => act(`/office/integrations/connections/${provider.key}/test`)} className="rounded-lg border border-[#e4dbd6] px-2.5 py-1 text-xs font-bold">Probar conexión</button>
                    {provider.key === 'hubspot' && <button type="button" onClick={() => act('/office/integrations/connections/hubspot/sync')} className="rounded-lg border border-[#e4dbd6] px-2.5 py-1 text-xs font-bold">Sincronizar ahora</button>}
                    {provider.key === 'calendar' && <button type="button" onClick={() => act('/office/integrations/connections/calendar/regenerate')} className="rounded-lg border border-[#e4dbd6] px-2.5 py-1 text-xs font-bold">Renovar enlace</button>}
                    <button type="button" onClick={() => act(`/office/integrations/connections/${provider.key}`, 'delete')} className="rounded-lg border border-red-100 px-2.5 py-1 text-xs font-bold text-red-600">Desconectar</button>
                </div>
            )}
        </article>
    );
}

function FelDialog({ item, onClose }: { item: Connected; onClose: () => void }) {
    return (
        <div className="fixed inset-0 z-[90] grid place-items-center bg-[#071a38]/50 p-4" role="dialog" aria-modal="true">
            <div className="max-h-[90vh] w-full max-w-lg space-y-3 overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div className="flex items-start justify-between gap-3">
                    <div><h2 className="text-xl font-black">Configurar FEL / SAT</h2><p className="text-sm text-slate-600">La facturación electrónica se configura por empresa cliente: NIT emisor, establecimiento y credenciales del certificador.</p></div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="size-5" /></button>
                </div>
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200">
                    {(item.companies ?? []).map((company) => (
                        <li key={company.public_id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                            <div className="min-w-0"><p className="truncate text-sm font-bold">{company.name}</p><p className={`text-[11px] font-bold ${company.configured ? 'text-emerald-600' : 'text-amber-600'}`}>{company.configured ? 'Con configuración FEL' : 'Sin configurar'}</p></div>
                            <Link href={`/accounting/companies/${company.public_id}/fel/settings`} className="shrink-0 rounded-lg border border-[#e4dbd6] px-3 py-1.5 text-xs font-bold">Configurar</Link>
                        </li>
                    ))}
                    {(item.companies ?? []).length === 0 && <li className="px-3 py-6 text-center text-sm text-slate-500">Aún no hay empresas clientes.</li>}
                </ul>
                <p className="text-xs text-slate-500">{item.detail}</p>
            </div>
        </div>
    );
}

export default function IntegrationsOverview({ kpis, connected, coming_soon, tokens, endpoints, history, canManage, providers, connection_events }: Props) {
    const [editing, setEditing] = useState<string | null>(null);
    const [felOpen, setFelOpen] = useState(false);
    const felItem = connected.find((item) => item.manage === 'fel') ?? null;
    const editingProvider = providers.find((provider) => provider.key === editing) ?? null;
    const [category, setCategory] = useState('');
    const categories = useMemo(() => Array.from(new Set([...connected.map((item) => item.category), ...coming_soon.map((item) => item.category)])).sort(), [connected, coming_soon]);
    const showConnected = connected.filter((item) => category === '' || item.category === category);
    const showSoon = coming_soon.filter((item) => category === '' || item.category === category);

    return (
        <>
            <Head title="Integraciones" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]"><Plug className="size-7" /></span>
                            <div><h1 className="text-3xl font-black tracking-tight">Integraciones</h1><p className="text-sm text-slate-600">Herramientas conectadas a tu firma y automatizaciones activas.</p></div>
                        </div>
                        {canManage && <Link href="/office/integrations?tab=keys" className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#8a0b0b] px-5 text-sm font-bold text-white">Gestionar claves y webhooks <ArrowRight className="size-4" /></Link>}
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Kpi icon={<Plug className="size-8" />} tone="bg-emerald-50 text-emerald-600" value={String(kpis.connected)} label="Integraciones conectadas" hint={`de ${kpis.available} en el catálogo`} />
                        <Kpi icon={<Layers className="size-8" />} tone="bg-blue-50 text-blue-600" value={String(kpis.automations)} label="Automatizaciones activas" hint="Webhooks activos" />
                        <Kpi icon={<RefreshCw className="size-8" />} tone="bg-rose-50 text-[#8a0b0b]" value={kpis.syncs_month.toLocaleString('es-GT')} label="Sincronizaciones este mes" hint="Entregas de webhooks y llamadas a la API" />
                        <Kpi icon={<ShieldCheck className="size-8" />} tone="bg-indigo-50 text-indigo-600" value={kpis.availability === null ? '—' : `${kpis.availability}%`} label="Disponibilidad de integraciones" hint="Últimos 30 días" />
                    </section>

                    <section className={card}>
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <div><h2 className="text-xl font-black">Integraciones disponibles</h2><p className="text-sm text-slate-500">Cada una se configura desde su tarjeta con los datos de tu firma.</p></div>
                            <select className="h-10 rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm" value={category} onChange={(e) => setCategory(e.target.value)}>
                                <option value="">Todas las categorías</option>
                                {categories.map((item) => <option key={item}>{item}</option>)}
                            </select>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                            {showConnected.map((item) => (
                                <article key={item.name} className="rounded-xl border border-slate-200 p-4">
                                    <div className="flex items-start gap-3">
                                        <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">{ICON[item.name]}</span>
                                        <div className="min-w-0"><div className="flex items-start justify-between gap-2"><p className="font-black">{item.name}</p></div><span className={`rounded-md px-2 py-0.5 text-[11px] font-bold ${CATEGORY_STYLE[item.category] ?? 'bg-slate-100'}`}>{item.category}</span></div>
                                    </div>
                                    <p className="mt-2 text-xs text-slate-600">{item.description}</p>
                                    <div className="mt-3 flex items-center justify-between">
                                        <span className={`flex items-center gap-1.5 text-sm font-bold ${item.status === 'connected' ? 'text-emerald-600' : 'text-slate-400'}`}><i className={`size-2.5 rounded-full ${item.status === 'connected' ? 'bg-emerald-500' : 'bg-slate-300'}`} />{item.status === 'connected' ? 'Conectada' : 'Sin configurar'}</span>
                                        {canManage && item.manage === 'fel' && <button type="button" onClick={() => setFelOpen(true)} className="rounded-lg border border-[#e4dbd6] px-3 py-1.5 text-xs font-bold">Configurar</button>}
                                        {canManage && item.manage && item.manage !== 'fel' && <Link href={`/office/integrations?tab=${item.manage}`} className="rounded-lg border border-[#e4dbd6] px-3 py-1.5 text-xs font-bold">Configurar</Link>}
                                    </div>
                                    <dl className="mt-3 grid grid-cols-2 gap-2 border-t border-slate-100 pt-2 text-[11px]"><div><dt className="text-slate-400">Última actividad</dt><dd className="font-bold">{formatWhen(item.last_sync)}</dd></div><div><dt className="text-slate-400">Estado</dt><dd className="font-bold">{item.detail}</dd></div></dl>
                                </article>
                            ))}
                            {providers.filter((provider) => category === '' || provider.category === category).map((provider) => (
                                <ProviderCard key={provider.key} provider={provider} canManage={canManage} onConfigure={() => setEditing(provider.key)} />
                            ))}
                            {showSoon.map((item) => (
                                <article key={item.name} className="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 p-4 opacity-80">
                                    <div className="flex items-start gap-3">
                                        <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-400"><Plug className="size-8" /></span>
                                        <div className="min-w-0"><p className="font-black">{item.name}</p><span className={`rounded-md px-2 py-0.5 text-[11px] font-bold ${CATEGORY_STYLE[item.category] ?? 'bg-slate-100'}`}>{item.category}</span></div>
                                    </div>
                                    <p className="mt-2 text-xs text-slate-600">{item.description}</p>
                                    <p className="mt-3 flex items-center gap-1.5 text-sm font-bold text-slate-400"><Clock3 className="size-4" /> Próximamente</p>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1fr_1fr_1.1fr]">
                        <div className={`${card} overflow-x-auto`}>
                            <h2 className="mb-1 text-lg font-black">Claves API</h2>
                            <p className="mb-3 text-xs text-slate-500">Claves de acceso para integraciones personalizadas.</p>
                            <table className="w-full min-w-[360px] text-sm">
                                <thead><tr className="bg-slate-50 text-left text-xs text-slate-600"><th className="px-2 py-2">Nombre</th><th className="px-2 py-2">Permisos</th><th className="px-2 py-2">Creación</th><th className="px-2 py-2">Estado</th></tr></thead>
                                <tbody className="divide-y divide-slate-100">
                                    {tokens.map((token) => (
                                        <tr key={token.prefix}>
                                            <td className="px-2 py-2.5"><p className="font-bold">{token.name}</p><p className="text-[11px] text-slate-400">{token.prefix}…</p></td>
                                            <td className="px-2 py-2.5 text-xs text-slate-600">{token.abilities.length} permiso(s)</td>
                                            <td className="px-2 py-2.5 text-xs">{new Date(token.created_at).toLocaleDateString('es-GT', { day: '2-digit', month: 'short', year: 'numeric' })}<br /><span className="text-slate-400">por {token.owner}</span></td>
                                            <td className="px-2 py-2.5"><span className={`flex items-center gap-1.5 text-xs font-bold ${token.status === 'active' ? 'text-emerald-600' : 'text-red-600'}`}><i className={`size-2 rounded-full ${token.status === 'active' ? 'bg-emerald-500' : 'bg-red-500'}`} />{token.status_label}</span></td>
                                        </tr>
                                    ))}
                                    {tokens.length === 0 && <tr><td colSpan={4} className="px-2 py-8 text-center text-slate-500">No hay claves API creadas.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                        <div className={`${card} overflow-x-auto`}>
                            <h2 className="mb-1 text-lg font-black">Webhooks</h2>
                            <p className="mb-3 text-xs text-slate-500">Eventos en tiempo real hacia otros sistemas.</p>
                            <table className="w-full min-w-[360px] text-sm">
                                <thead><tr className="bg-slate-50 text-left text-xs text-slate-600"><th className="px-2 py-2">Destino</th><th className="px-2 py-2">URL</th><th className="px-2 py-2">Estado</th></tr></thead>
                                <tbody className="divide-y divide-slate-100">
                                    {endpoints.map((endpoint) => (
                                        <tr key={endpoint.url}>
                                            <td className="px-2 py-2.5"><p className="font-bold">{endpoint.name}</p><p className="text-[11px] text-slate-400">{endpoint.events.length} evento(s)</p></td>
                                            <td className="max-w-40 truncate px-2 py-2.5 text-xs text-slate-600">{endpoint.url}</td>
                                            <td className="px-2 py-2.5"><span className={`flex items-center gap-1.5 text-xs font-bold ${endpoint.status === 'active' ? 'text-emerald-600' : 'text-amber-600'}`}><i className={`size-2 rounded-full ${endpoint.status === 'active' ? 'bg-emerald-500' : 'bg-amber-500'}`} />{endpoint.status === 'active' ? 'Activo' : endpoint.failures > 0 ? 'Con errores' : 'Pausado'}</span></td>
                                        </tr>
                                    ))}
                                    {endpoints.length === 0 && <tr><td colSpan={3} className="px-2 py-8 text-center text-slate-500">No hay webhooks configurados.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                        <div className={`${card} overflow-x-auto`}>
                            <h2 className="mb-1 text-lg font-black">Historial de sincronizaciones</h2>
                            <p className="mb-3 text-xs text-slate-500">Entregas de webhooks y llamadas a la API.</p>
                            <table className="w-full min-w-[380px] text-sm">
                                <thead><tr className="bg-slate-50 text-left text-xs text-slate-600"><th className="px-2 py-2">Fecha</th><th className="px-2 py-2">Integración</th><th className="px-2 py-2">Evento</th><th className="px-2 py-2">Resultado</th></tr></thead>
                                <tbody className="divide-y divide-slate-100">
                                    {history.map((row, index) => (
                                        <tr key={index}>
                                            <td className="px-2 py-2.5 text-xs">{formatWhen(row.at)}</td><td className="max-w-28 truncate px-2 py-2.5 font-bold">{row.integration}</td><td className="max-w-32 truncate px-2 py-2.5 text-xs text-slate-600">{row.event}</td>
                                            <td className="px-2 py-2.5"><span className={`flex items-center gap-1.5 text-xs font-bold ${row.pending ? 'text-amber-600' : row.ok ? 'text-emerald-600' : 'text-red-600'}`}><i className={`size-2 rounded-full ${row.pending ? 'bg-amber-500' : row.ok ? 'bg-emerald-500' : 'bg-red-500'}`} />{row.pending ? 'Pendiente' : row.ok ? 'Exitoso' : 'Error'}</span></td>
                                        </tr>
                                    ))}
                                    {history.length === 0 && <tr><td colSpan={4} className="px-2 py-8 text-center text-slate-500">Aún no hay sincronizaciones.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section className={`${card} overflow-x-auto`}>
                        <h2 className="mb-1 text-lg font-black">Actividad de las integraciones</h2>
                        <p className="mb-3 text-xs text-slate-500">Avisos enviados, pruebas y sincronizaciones de las herramientas conectadas.</p>
                        <ul className="divide-y divide-slate-100 text-sm">
                            {connection_events.map((item, index) => (
                                <li key={index} className="flex items-center gap-3 py-2.5">
                                    <i className={`size-2.5 shrink-0 rounded-full ${item.ok ? 'bg-emerald-500' : 'bg-red-500'}`} />
                                    <b className="w-24 shrink-0">{item.provider}</b>
                                    <span className="flex-1 truncate text-slate-600">{item.message ?? item.event}</span>
                                    <span className="text-xs text-slate-400">{formatWhen(item.at)}</span>
                                </li>
                            ))}
                            {connection_events.length === 0 && <li className="py-6 text-center text-slate-500">Todavía no hay actividad. Conecta una herramienta y prueba la conexión.</li>}
                        </ul>
                    </section>
                    {editingProvider && <ConnectDialog key={editingProvider.key} provider={editingProvider} onClose={() => setEditing(null)} />}
                    {felOpen && felItem && <FelDialog item={felItem} onClose={() => setFelOpen(false)} />}
                    <p className="flex items-center gap-2 text-xs text-slate-500"><CheckCircle2 className="size-4 text-emerald-600" /> Solo se muestran como conectadas las integraciones que Atlantia realmente tiene activas.</p>
                </div>
            </main>
        </>
    );
}
