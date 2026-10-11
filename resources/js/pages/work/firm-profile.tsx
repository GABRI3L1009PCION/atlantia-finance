import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Building2,
    Palette,
    Phone,
    Mail,
    MapPin,
    Plus,
    Save,
    Settings,
    Trash2,
    Upload,
    Users,
    FileText,
} from 'lucide-react';
import { useRef } from 'react';
import type { FormEvent, ReactNode } from 'react';

type Profile = {
    trade_name: string;
    legal_name: string | null;
    tax_id: string | null;
    organization_type: string | null;
    founded_year: number | null;
    website: string | null;
    description: string | null;
    country: string;
    department: string | null;
    municipality: string | null;
    fiscal_address: string | null;
    postal_code: string | null;
    logo_url: string | null;
    primary_color: string;
    secondary_color: string;
    general_email: string | null;
    phone: string | null;
    billing_email: string | null;
    timezone: string;
    currency: string;
    date_format: string;
    number_format: string;
    logo_in_reports: boolean;
    letterhead_in_communications: boolean;
};

type Props = {
    profile: Profile;
    offices: { id: number; name: string; city: string | null; address_line_1: string | null; phone: string | null; status: string; is_primary: boolean }[];
    canManage: boolean;
    types: string[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-6 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const fieldClass =
    'h-11 w-full rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b] disabled:bg-slate-50 disabled:text-slate-600';
const SECTIONS = [
    ['datos', 'Información general', FileText],
    ['identidad', 'Identidad visual', Palette],
    ['oficinas', 'Oficinas', Building2],
    ['contactos', 'Contactos', Users],
    ['preferencias', 'Preferencias operativas', Settings],
] as const;
const TIMEZONES = ['America/Guatemala', 'America/Mexico_City', 'America/Bogota', 'America/New_York', 'UTC'];

function Field({ label, required, children }: { label: string; required?: boolean; children: ReactNode }) {
    return (
        <label className="block min-w-0 text-sm font-bold text-[#2b2f3a]">
            <span className="mb-1.5 block">
                {label} {required && <span className="text-red-600">*</span>}
            </span>
            {children}
        </label>
    );
}

function Heading({ icon, title, hint }: { icon: ReactNode; title: string; hint: string }) {
    return (
        <div className="mb-5 flex items-center gap-3">
            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">{icon}</span>
            <div>
                <h2 className="text-lg font-black">{title}</h2>
                <p className="text-xs text-slate-500">{hint}</p>
            </div>
        </div>
    );
}

export default function FirmProfile({ profile, offices, canManage, types }: Props) {
    const form = useForm({
        trade_name: profile.trade_name,
        legal_name: profile.legal_name ?? '',
        tax_id: profile.tax_id ?? '',
        organization_type: profile.organization_type ?? '',
        founded_year: profile.founded_year ? String(profile.founded_year) : '',
        website: profile.website ?? '',
        description: profile.description ?? '',
        country: profile.country,
        department: profile.department ?? '',
        municipality: profile.municipality ?? '',
        fiscal_address: profile.fiscal_address ?? '',
        postal_code: profile.postal_code ?? '',
        primary_color: profile.primary_color,
        secondary_color: profile.secondary_color,
        general_email: profile.general_email ?? '',
        phone: profile.phone ?? '',
        billing_email: profile.billing_email ?? '',
        timezone: profile.timezone,
        currency: profile.currency,
        date_format: profile.date_format,
        number_format: profile.number_format,
        logo_in_reports: profile.logo_in_reports,
        letterhead_in_communications: profile.letterhead_in_communications,
    });
    const file = useRef<HTMLInputElement>(null);
    const lock = !canManage;
    const set = (key: keyof typeof form.data) => (event: { target: { value: string } }) => form.setData(key as never, event.target.value as never);
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.patch('/office/firm-profile', { preserveScroll: true });
    };
    const upload = (event: { target: HTMLInputElement }) => {
        const chosen = event.target.files?.[0];

        if (chosen) {
            router.post('/office/firm-profile/logo', { logo: chosen }, { forceFormData: true, preserveScroll: true });
        }
    };
    const initials = form.data.trade_name.split(' ').filter(Boolean).slice(0, 2).map((word) => word[0]).join('').toUpperCase();

    return (
        <>
            <Head title="Perfil de la firma" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <form onSubmit={save} className="mx-auto max-w-[1500px] space-y-4">
                    <header className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]"><Settings className="size-7" /></span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">Perfil de la firma</h1>
                                <p className="text-sm text-slate-600">Información, identidad, oficinas y preferencias de tu firma contable{lock ? ' (solo consulta).' : '.'}</p>
                            </div>
                        </div>
                        {canManage && <button className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#8a0b0b] px-5 text-sm font-bold text-white disabled:opacity-60" disabled={form.processing}><Save className="size-4" /> Guardar cambios</button>}
                    </header>

                    <nav className="flex flex-wrap gap-6 border-b border-slate-200 bg-white px-4 text-sm font-bold">
                        {SECTIONS.map(([id, label, Icon]) => (
                            <a key={id} href={`#${id}`} className="flex items-center gap-2 border-b-2 border-transparent py-3.5 text-slate-600 hover:border-[#8a0b0b] hover:text-[#8a0b0b]"><Icon className="size-4" /> {label}</a>
                        ))}
                    </nav>

                    <div className="grid gap-4 xl:grid-cols-2">
                        <section id="datos" className={`${card} scroll-mt-20`}>
                            <Heading icon={<FileText className="size-6" />} title="Datos generales" hint="Información básica de la firma contable." />
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Nombre comercial" required><input className={fieldClass} disabled={lock} value={form.data.trade_name} onChange={set('trade_name')} /></Field>
                                <Field label="Razón social"><input className={fieldClass} disabled={lock} value={form.data.legal_name} onChange={set('legal_name')} /></Field>
                                <Field label="NIT"><input className={fieldClass} disabled={lock} value={form.data.tax_id} onChange={set('tax_id')} /></Field>
                                <Field label="Tipo de organización">
                                    <select className={fieldClass} disabled={lock} value={form.data.organization_type} onChange={set('organization_type')}>
                                        <option value="">Selecciona</option>
                                        {types.map((type) => <option key={type}>{type}</option>)}
                                    </select>
                                </Field>
                                <Field label="Año de fundación"><input className={fieldClass} type="number" disabled={lock} value={form.data.founded_year} onChange={set('founded_year')} /></Field>
                                <Field label="Sitio web"><input className={fieldClass} disabled={lock} placeholder="https://" value={form.data.website} onChange={set('website')} /></Field>
                            </div>
                            <div className="mt-4">
                                <Field label="Descripción de la firma">
                                    <textarea className={`${fieldClass} h-28 py-2`} maxLength={500} disabled={lock} value={form.data.description} onChange={set('description')} />
                                </Field>
                                <p className="mt-1 text-right text-xs text-slate-400">{form.data.description.length}/500</p>
                            </div>
                            <div className="mt-6 border-t border-slate-100 pt-6">
                                <Heading icon={<MapPin className="size-6" />} title="Dirección fiscal" hint="Dirección registrada ante la SAT y usada en documentos oficiales." />
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field label="País" required><input className={fieldClass} disabled={lock} value={form.data.country} onChange={set('country')} /></Field>
                                    <Field label="Departamento"><input className={fieldClass} disabled={lock} value={form.data.department} onChange={set('department')} /></Field>
                                    <Field label="Municipio"><input className={fieldClass} disabled={lock} value={form.data.municipality} onChange={set('municipality')} /></Field>
                                    <div className="sm:col-span-2"><Field label="Dirección fiscal"><input className={fieldClass} disabled={lock} value={form.data.fiscal_address} onChange={set('fiscal_address')} /></Field></div>
                                    <Field label="Código postal"><input className={fieldClass} disabled={lock} value={form.data.postal_code} onChange={set('postal_code')} /></Field>
                                </div>
                            </div>
                        </section>

                        <div className="space-y-4">
                            <section id="identidad" className={`${card} scroll-mt-20`}>
                                <Heading icon={<Palette className="size-6" />} title="Identidad visual" hint="Personaliza la imagen de tu firma en la plataforma y en los documentos." />
                                <div className="grid gap-5 sm:grid-cols-[auto_1fr]">
                                    <div>
                                        <p className="mb-2 text-sm font-bold">Logo de la firma</p>
                                        <div className="grid size-44 place-items-center rounded-xl border-2 border-dashed border-slate-200 bg-slate-50">
                                            {profile.logo_url ? <img src={profile.logo_url} alt="Logo de la firma" className="max-h-40 max-w-40 object-contain" /> : <span className="text-center text-xs font-bold text-slate-400">{initials || 'Sin logo'}<br />Sin logo cargado</span>}
                                        </div>
                                        {canManage && (
                                            <div className="mt-2 flex gap-2">
                                                <input ref={file} type="file" accept="image/png,image/jpeg" className="hidden" onChange={upload} />
                                                <button type="button" onClick={() => file.current?.click()} className="inline-flex items-center gap-1.5 rounded-lg border border-[#e4dbd6] px-3 py-2 text-xs font-bold"><Upload className="size-3.5" /> Cambiar logo</button>
                                                {profile.logo_url && <button type="button" onClick={() => router.delete('/office/firm-profile/logo', { preserveScroll: true })} className="inline-flex items-center gap-1.5 rounded-lg border border-red-100 px-3 py-2 text-xs font-bold text-red-600"><Trash2 className="size-3.5" /> Eliminar</button>}
                                            </div>
                                        )}
                                        <p className="mt-1 text-xs text-slate-400">PNG o JPG, hasta 1 MB.</p>
                                    </div>
                                    <div className="space-y-4">
                                        <div className="grid grid-cols-2 gap-4">
                                            {([['primary_color', 'Color primario'], ['secondary_color', 'Color secundario']] as const).map(([key, label]) => (
                                                <Field key={key} label={label}>
                                                    <div className="flex items-center gap-2">
                                                        <input type="color" disabled={lock} value={form.data[key]} onChange={set(key)} className="size-11 shrink-0 cursor-pointer rounded-full border-0 bg-transparent p-0" />
                                                        <input className={fieldClass} disabled={lock} value={form.data[key]} onChange={set(key)} />
                                                    </div>
                                                </Field>
                                            ))}
                                        </div>
                                        <div>
                                            <p className="mb-2 text-sm font-bold">Vista previa</p>
                                            <div className="flex items-center gap-4 rounded-xl p-4" style={{ background: form.data.secondary_color }}>
                                                <span className="grid size-12 place-items-center rounded-lg text-lg font-black text-white" style={{ background: form.data.primary_color }}>{initials || 'F'}</span>
                                                <p className="font-black" style={{ color: form.data.primary_color }}>{form.data.trade_name}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section id="contactos" className={`${card} scroll-mt-20`}>
                                <Heading icon={<Users className="size-6" />} title="Contactos principales" hint="Información de contacto de la firma." />
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field label="Correo general"><div className="relative"><Mail className="absolute top-3.5 left-3 size-4 text-[#8a0b0b]" /><input className={`${fieldClass} pl-9`} disabled={lock} value={form.data.general_email} onChange={set('general_email')} /></div></Field>
                                    <Field label="Teléfono principal"><div className="relative"><Phone className="absolute top-3.5 left-3 size-4 text-[#8a0b0b]" /><input className={`${fieldClass} pl-9`} disabled={lock} value={form.data.phone} onChange={set('phone')} /></div></Field>
                                    <Field label="Correo de facturación"><div className="relative"><FileText className="absolute top-3.5 left-3 size-4 text-[#8a0b0b]" /><input className={`${fieldClass} pl-9`} disabled={lock} value={form.data.billing_email} onChange={set('billing_email')} /></div></Field>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div className="grid gap-4 xl:grid-cols-2">
                        <section id="oficinas" className={`${card} scroll-mt-20 overflow-x-auto`}>
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <Heading icon={<Building2 className="size-6" />} title="Sucursales y oficinas" hint="Las oficinas de tu firma." />
                                <Link href="/office/offices" className="inline-flex shrink-0 items-center gap-2 rounded-xl bg-[#8a0b0b] px-4 py-2.5 text-sm font-bold text-white"><Plus className="size-4" /> {canManage ? 'Nueva oficina' : 'Ver oficinas'}</Link>
                            </div>
                            <table className="w-full min-w-[460px] text-sm">
                                <thead><tr className="bg-slate-50 text-left text-xs text-slate-600"><th className="px-3 py-2">Oficina</th><th className="px-3 py-2">Ciudad</th><th className="px-3 py-2">Dirección</th><th className="px-3 py-2">Teléfono</th><th className="px-3 py-2">Estado</th></tr></thead>
                                <tbody className="divide-y divide-slate-100">
                                    {offices.map((office) => (
                                        <tr key={office.id}>
                                            <td className="px-3 py-3 font-bold">{office.name}</td><td className="px-3 py-3">{office.city ?? '—'}</td><td className="px-3 py-3 text-slate-600">{office.address_line_1 ?? '—'}</td><td className="px-3 py-3">{office.phone ?? '—'}</td>
                                            <td className="px-3 py-3"><span className={`rounded-md px-2 py-1 text-xs font-bold ${office.is_primary ? 'bg-emerald-50 text-emerald-700' : office.status === 'active' ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'}`}>{office.is_primary ? 'Principal' : office.status === 'active' ? 'Activa' : 'Inactiva'}</span></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>

                        <section id="preferencias" className={`${card} scroll-mt-20`}>
                            <Heading icon={<Settings className="size-6" />} title="Preferencias operativas" hint="Configuraciones regionales y operativas de la firma." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Zona horaria" required>
                                    <select className={fieldClass} disabled={lock} value={form.data.timezone} onChange={set('timezone')}>
                                        {Array.from(new Set([form.data.timezone, ...TIMEZONES])).map((zone) => <option key={zone}>{zone}</option>)}
                                    </select>
                                </Field>
                                <Field label="Moneda principal" required>
                                    <select className={fieldClass} disabled={lock} value={form.data.currency} onChange={set('currency')}><option value="GTQ">Quetzal (GTQ)</option><option value="USD">Dólar (USD)</option></select>
                                </Field>
                                <Field label="Formato de fecha">
                                    <select className={fieldClass} disabled={lock} value={form.data.date_format} onChange={set('date_format')}><option>DD/MM/YYYY</option><option>MM/DD/YYYY</option><option>YYYY-MM-DD</option></select>
                                </Field>
                                <Field label="Formato de números">
                                    <select className={fieldClass} disabled={lock} value={form.data.number_format} onChange={set('number_format')}><option value="es-GT">Español (1,234,567.89)</option><option value="en-US">Inglés (1,234,567.89)</option></select>
                                </Field>
                            </div>
                            <div className="mt-5 grid gap-4 sm:grid-cols-2">
                                {([['logo_in_reports', 'Incluir logo en reportes y documentos', 'El logo se mostrará en los reportes generados por la plataforma.'], ['letterhead_in_communications', 'Usar membrete en comunicaciones', 'Aplicar la identidad visual en correos y notificaciones.']] as const).map(([key, title, hint]) => (
                                    <label key={key} className="flex items-start gap-3">
                                        <input type="checkbox" disabled={lock} checked={form.data[key]} onChange={(event) => form.setData(key, event.target.checked)} className="mt-1 size-5 accent-[#8a0b0b]" />
                                        <span><span className="block text-sm font-bold">{title}</span><span className="block text-xs text-slate-500">{hint}</span></span>
                                    </label>
                                ))}
                            </div>
                        </section>
                    </div>
                </form>
            </main>
        </>
    );
}
