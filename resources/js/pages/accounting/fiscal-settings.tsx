import { Head, router, useForm } from '@inertiajs/react';
import {
    BadgeCheck,
    Banknote,
    CalendarClock,
    CircleDollarSign,
    FileCheck2,
    Landmark,
    RefreshCw,
    ReceiptText,
    Save,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    primary,
    secondary,
    shortDate,
} from '@/components/accounting-ui';
import { TaxCatalog } from '@/components/fiscal/tax-catalog';
import type {
    FiscalTax,
    LedgerAccountOption,
} from '@/components/fiscal/tax-catalog';

type FiscalProfile = {
    country_code: string;
    base_currency: string;
    timezone: string;
    vat_regime: string;
    income_tax_regime: string;
    accounting_template: string;
    exchange_rate_source: string;
    fiscal_year_starts_in: number;
};

type ExchangeRate = {
    id: string;
    currency: string;
    quote_currency: string;
    rate: string;
    source: string;
    effective_on: string;
    reference: string | null;
    origin: 'company' | 'official';
};

type Overview = {
    obligation: { name: string; due_on: string; period: string } | null;
    fel: { configured: boolean; mode: string | null };
    integrity: { percent: number; missing: string[] };
};

const labels: Record<string, string> = {
    general: 'Régimen general',
    small_taxpayer: 'Pequeño contribuyente',
    exempt: 'Exento',
    utilities: 'Sobre utilidades',
    optional_simplified: 'Opcional simplificado',
    guatemala_general: 'Contabilidad general de Guatemala',
    guatemala_services: 'Empresa de servicios',
    guatemala_commerce: 'Empresa comercial',
    vat: 'IVA',
    income_tax: 'ISR',
    withholding: 'Retención',
};

export default function FiscalSettings({
    company,
    profile,
    taxes,
    accounts,
    today,
    exchangeRates,
    canManage,
    overview,
}: {
    company: Company;
    profile: FiscalProfile;
    taxes: FiscalTax[];
    accounts: LedgerAccountOption[];
    today: string;
    exchangeRates: ExchangeRate[];
    canManage: boolean;
    overview: Overview;
}) {
    const endpoint = `/accounting/companies/${company.public_id}/fiscal-settings`;
    const profileForm = useForm({ ...profile });
    const [syncing, setSyncing] = useState(false);
    const exchangeForm = useForm({
        currency: 'USD',
        rate: '',
        source: profile.exchange_rate_source,
        effective_on: today,
        reference: 'https://www.banguat.gob.gt/variables/ws/tipocambio.asmx',
    });

    return (
        <main className="min-h-full bg-[#f5f8fc] p-3 text-[#142135] sm:p-6">
            <Head title={`Parámetros fiscales · ${company.name}`} />
            <div className="mx-auto max-w-[1800px] space-y-5">
                <div className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Configuración</span>
                    <span>›</span>
                    <strong>Parámetros fiscales</strong>
                </div>
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold">
                            Parámetros fiscales
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Configura regímenes, impuestos, vigencias y tipos de
                            cambio para Guatemala.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <span className="inline-flex items-center gap-2 rounded-lg bg-emerald-100 px-4 py-2 text-xs font-semibold text-emerald-700">
                            <ShieldCheck className="size-4" /> Configuración
                            vigente
                        </span>
                        <a
                            href="#reglas-fiscales"
                            className="inline-flex items-center gap-2 rounded-lg bg-[#73070e] px-5 py-2 text-xs font-semibold text-white"
                        >
                            <CalendarClock className="size-4" /> Revisar
                            vigencias
                        </a>
                    </div>
                </header>
                <nav className="flex overflow-x-auto rounded-xl border border-slate-200 bg-white px-2 shadow-sm">
                    {[
                        ['Resumen', '#resumen-fiscal'],
                        ['Perfil tributario', '#perfil-fiscal'],
                        ['Tipo de cambio', '#tipo-cambio'],
                        ['Reglas fiscales', '#reglas-fiscales'],
                        ['Obligaciones', '#obligaciones-fiscales'],
                    ].map(([label, href], index) => (
                        <a
                            key={label}
                            href={href}
                            className={`border-b-2 px-5 py-3 text-xs font-semibold whitespace-nowrap ${index === 0 ? 'border-[#73070e] text-[#73070e]' : 'border-transparent text-slate-500'}`}
                        >
                            {label}
                        </a>
                    ))}
                </nav>
                <div
                    id="resumen-fiscal"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    {[
                        [
                            Landmark,
                            'Jurisdicción',
                            profile.country_code,
                            'SAT Guatemala',
                        ],
                        [
                            CircleDollarSign,
                            'Moneda base',
                            profile.base_currency,
                            'Quetzal como referencia',
                        ],
                        [
                            ReceiptText,
                            'Régimen IVA',
                            labels[profile.vat_regime],
                            'Reglas con vigencia',
                        ],
                        [
                            FileCheck2,
                            'Régimen ISR',
                            labels[profile.income_tax_regime],
                            'Cálculo parametrizado',
                        ],
                    ].map(([Icon, title, value, detail]) => {
                        const ItemIcon = Icon as typeof Landmark;

                        return (
                            <div
                                key={String(title)}
                                className="rounded-2xl border border-[#eadfdf] bg-white p-5 shadow-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                            {String(title)}
                                        </p>
                                        <p className="mt-2 text-xl font-bold text-[#142135]">
                                            {String(value)}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {String(detail)}
                                        </p>
                                    </div>
                                    <span className="rounded-xl bg-[#f8eaea] p-3 text-[#76080e]">
                                        <ItemIcon className="size-5" />
                                    </span>
                                </div>
                            </div>
                        );
                    })}
                </div>

                <section
                    id="obligaciones-fiscales"
                    className="grid gap-4 md:grid-cols-3"
                >
                    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="grid size-10 place-items-center rounded-xl bg-blue-50 text-blue-600">
                                <CalendarClock className="size-5" />
                            </span>
                            <span className="rounded-full bg-amber-100 px-2 py-1 text-[10px] font-semibold text-amber-700">
                                Próximo
                            </span>
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            Próxima obligación fiscal
                        </p>
                        <strong className="mt-1 block text-lg">
                            {overview.obligation
                                ? new Date(
                                      `${overview.obligation.due_on}T12:00:00`,
                                  ).toLocaleDateString('es-GT', {
                                      day: 'numeric',
                                      month: 'long',
                                      year: 'numeric',
                                  })
                                : 'Sin obligaciones pendientes'}
                        </strong>
                        <p className="mt-1 text-[10px] text-slate-500">
                            {overview.obligation
                                ? `${overview.obligation.name} · ${overview.obligation.period}`
                                : 'Se generan desde el perfil tributario de la empresa.'}
                        </p>
                    </article>
                    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="grid size-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                                <FileCheck2 className="size-5" />
                            </span>
                            <span
                                className={`rounded-full px-2 py-1 text-[10px] font-semibold ${overview.fel.configured ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}
                            >
                                {overview.fel.configured
                                    ? 'Activo'
                                    : 'Pendiente'}
                            </span>
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            Facturación electrónica
                        </p>
                        <strong className="mt-1 block text-lg">
                            {overview.fel.configured
                                ? 'FEL configurada'
                                : 'FEL sin configurar'}
                        </strong>
                        <p className="mt-1 text-[10px] text-slate-500">
                            {overview.fel.mode === 'sat_manual'
                                ? 'Emisión en el portal SAT'
                                : overview.fel.configured
                                  ? 'Con certificador'
                                  : 'Elige cómo se emiten las facturas'}{' '}
                            · NIT {company.tax_id}
                        </p>
                    </article>
                    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="grid size-10 place-items-center rounded-xl bg-violet-50 text-violet-600">
                                <BadgeCheck className="size-5" />
                            </span>
                            <span
                                className={`text-lg font-bold ${overview.integrity.percent === 100 ? 'text-emerald-600' : 'text-amber-600'}`}
                            >
                                {overview.integrity.percent}%
                            </span>
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            Integridad de parámetros
                        </p>
                        <strong className="mt-1 block text-lg">
                            {overview.integrity.missing.length === 0
                                ? 'Configuración completa'
                                : `Falta: ${overview.integrity.missing.join(', ')}`}
                        </strong>
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <span
                                className="block h-full bg-emerald-500"
                                style={{
                                    width: `${overview.integrity.percent}%`,
                                }}
                            />
                        </div>
                    </article>
                </section>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,.95fr)]">
                    <div id="perfil-fiscal">
                        <Card title="Configuración regional y tributaria">
                            <form
                                className="grid gap-4 sm:grid-cols-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    profileForm.patch(endpoint, {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <Errors errors={profileForm.errors} />
                                <label className="text-sm font-medium">
                                    País
                                    <select
                                        className={field}
                                        value={profileForm.data.country_code}
                                        onChange={(event) =>
                                            profileForm.setData(
                                                'country_code',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        <option value="GT">Guatemala</option>
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Moneda base
                                    <select
                                        className={field}
                                        value={profileForm.data.base_currency}
                                        onChange={(event) =>
                                            profileForm.setData(
                                                'base_currency',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        <option value="GTQ">
                                            GTQ · Quetzal
                                        </option>
                                        <option value="USD">USD · Dólar</option>
                                        <option value="EUR">EUR · Euro</option>
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Régimen de IVA
                                    <select
                                        className={field}
                                        value={profileForm.data.vat_regime}
                                        onChange={(event) =>
                                            profileForm.setData(
                                                'vat_regime',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        <option value="general">
                                            General · 12%
                                        </option>
                                        <option value="small_taxpayer">
                                            Pequeño contribuyente · 5%
                                        </option>
                                        <option value="exempt">Exento</option>
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Régimen de ISR
                                    <select
                                        className={field}
                                        value={
                                            profileForm.data.income_tax_regime
                                        }
                                        onChange={(event) =>
                                            profileForm.setData(
                                                'income_tax_regime',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        <option value="utilities">
                                            Sobre utilidades · 25%
                                        </option>
                                        <option value="optional_simplified">
                                            Opcional simplificado · 5%/7%
                                        </option>
                                        <option value="exempt">Exento</option>
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Plantilla contable
                                    <select
                                        className={field}
                                        value={
                                            profileForm.data.accounting_template
                                        }
                                        onChange={(event) =>
                                            profileForm.setData(
                                                'accounting_template',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        <option value="guatemala_general">
                                            General de Guatemala
                                        </option>
                                        <option value="guatemala_services">
                                            Servicios
                                        </option>
                                        <option value="guatemala_commerce">
                                            Comercio
                                        </option>
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Fuente cambiaria
                                    <select
                                        className={field}
                                        value={
                                            profileForm.data
                                                .exchange_rate_source
                                        }
                                        onChange={(event) =>
                                            profileForm.setData(
                                                'exchange_rate_source',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        <option value="BANGUAT">
                                            Banco de Guatemala
                                        </option>
                                        <option value="MANUAL">
                                            Registro manual controlado
                                        </option>
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Inicio del ejercicio
                                    <select
                                        className={field}
                                        value={
                                            profileForm.data
                                                .fiscal_year_starts_in
                                        }
                                        onChange={(event) =>
                                            profileForm.setData(
                                                'fiscal_year_starts_in',
                                                Number(event.target.value),
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        {Array.from(
                                            { length: 12 },
                                            (_, index) => (
                                                <option
                                                    key={index + 1}
                                                    value={index + 1}
                                                >
                                                    {new Date(
                                                        2026,
                                                        index,
                                                        1,
                                                    ).toLocaleDateString(
                                                        'es-GT',
                                                        {
                                                            month: 'long',
                                                        },
                                                    )}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Zona horaria
                                    <input
                                        className={field}
                                        value={profileForm.data.timezone}
                                        readOnly
                                    />
                                </label>
                                {canManage && (
                                    <div className="sm:col-span-2">
                                        <button
                                            className={primary}
                                            disabled={profileForm.processing}
                                        >
                                            <Save className="size-4" /> Guardar
                                            configuración
                                        </button>
                                    </div>
                                )}
                            </form>
                        </Card>
                    </div>

                    <div id="tipo-cambio">
                        <Card title="Tipo de cambio oficial o controlado">
                            <form
                                className="grid gap-4 sm:grid-cols-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    exchangeForm.post(
                                        `${endpoint}/exchange-rates`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                exchangeForm.reset('rate'),
                                        },
                                    );
                                }}
                            >
                                <Errors errors={exchangeForm.errors} />
                                <label className="text-sm font-medium">
                                    Moneda
                                    <select
                                        className={field}
                                        value={exchangeForm.data.currency}
                                        onChange={(event) =>
                                            exchangeForm.setData(
                                                'currency',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        <option value="USD">USD</option>
                                        <option value="EUR">EUR</option>
                                    </select>
                                </label>
                                <label className="text-sm font-medium">
                                    Quetzales por unidad
                                    <input
                                        className={field}
                                        placeholder="7.65000000"
                                        value={exchangeForm.data.rate}
                                        onChange={(event) =>
                                            exchangeForm.setData(
                                                'rate',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    />
                                </label>
                                <label className="text-sm font-medium">
                                    Fecha efectiva
                                    <input
                                        type="date"
                                        className={field}
                                        value={exchangeForm.data.effective_on}
                                        onChange={(event) =>
                                            exchangeForm.setData(
                                                'effective_on',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    />
                                </label>
                                <label className="text-sm font-medium">
                                    Fuente
                                    <select
                                        className={field}
                                        value={exchangeForm.data.source}
                                        onChange={(event) =>
                                            exchangeForm.setData(
                                                'source',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    >
                                        <option value="BANGUAT">BANGUAT</option>
                                        <option value="MANUAL">
                                            Manual verificado
                                        </option>
                                    </select>
                                </label>
                                {canManage && (
                                    <div className="sm:col-span-2">
                                        <button
                                            className={primary}
                                            disabled={exchangeForm.processing}
                                        >
                                            <Banknote className="size-4" />{' '}
                                            Registrar tipo de cambio
                                        </button>
                                    </div>
                                )}
                            </form>
                            <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm text-slate-600">
                                    Atlantia descarga cada día el tipo de cambio
                                    oficial del Banco de Guatemala. Si registras
                                    uno propio para la misma fecha, se usa el
                                    tuyo.
                                </p>
                                {canManage && (
                                    <button
                                        type="button"
                                        className={secondary}
                                        disabled={syncing}
                                        onClick={() =>
                                            router.post(
                                                `${endpoint}/exchange-rates/sync`,
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onStart: () =>
                                                        setSyncing(true),
                                                    onFinish: () =>
                                                        setSyncing(false),
                                                },
                                            )
                                        }
                                    >
                                        <RefreshCw className="size-4" />{' '}
                                        {syncing
                                            ? 'Consultando Banguat…'
                                            : 'Actualizar desde Banguat'}
                                    </button>
                                )}
                            </div>
                            <div className="mt-3 overflow-hidden rounded-xl border border-slate-200">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-slate-50 text-xs text-slate-500 uppercase">
                                        <tr>
                                            <th className="p-3">Fecha</th>
                                            <th className="p-3">Moneda</th>
                                            <th className="p-3">Tasa</th>
                                            <th className="p-3">Fuente</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {exchangeRates.map((rate) => (
                                            <tr
                                                key={rate.id}
                                                className="border-t border-slate-100"
                                            >
                                                <td className="p-3">
                                                    {shortDate(
                                                        rate.effective_on,
                                                    )}
                                                </td>
                                                <td className="p-3 font-semibold">
                                                    {rate.currency}/
                                                    {rate.quote_currency}
                                                </td>
                                                <td className="p-3 tabular-nums">
                                                    {Number(rate.rate).toFixed(
                                                        6,
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {rate.origin === 'official'
                                                        ? 'Banguat · automático'
                                                        : rate.source ===
                                                            'MANUAL'
                                                          ? 'Manual'
                                                          : `${rate.source} · registrado por la empresa`}
                                                </td>
                                            </tr>
                                        ))}
                                        {exchangeRates.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={4}
                                                    className="p-4 text-center text-slate-500"
                                                >
                                                    Aún no hay tipos de cambio.
                                                    Pulsa «Actualizar desde
                                                    Banguat» o registra uno
                                                    manualmente.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    </div>
                </div>

                <div id="reglas-fiscales">
                    <TaxCatalog
                        company={company.public_id}
                        taxes={taxes}
                        accounts={accounts}
                        today={today}
                        canManage={canManage}
                    />
                </div>
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
                    <p className="flex items-center gap-2 font-semibold">
                        <BadgeCheck className="size-5" /> Control profesional
                        requerido
                    </p>
                    <p className="mt-2">
                        Estas reglas parametrizan los cálculos internos y
                        conservan su vigencia histórica. Antes de presentar
                        formularios o emitir FEL, el contador debe validar el
                        RTU, el régimen y la normativa aplicable ante SAT.
                    </p>
                </div>
                <footer className="flex flex-wrap items-center justify-between gap-2 text-[10px] text-slate-400">
                    <span>
                        {company.name} · Parámetros fiscales de Guatemala
                    </span>
                    <span>
                        Zona horaria: {profile.timezone} · Moneda:{' '}
                        {profile.base_currency}
                    </span>
                </footer>
            </div>
        </main>
    );
}
