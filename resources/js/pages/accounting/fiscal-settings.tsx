import { Head, useForm } from '@inertiajs/react';
import {
    BadgeCheck,
    Banknote,
    CalendarClock,
    CircleDollarSign,
    FileCheck2,
    Landmark,
    Plus,
    ReceiptText,
    Save,
    ShieldCheck,
} from 'lucide-react';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    money,
    primary,
} from '@/components/accounting-ui';

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

type TaxRule = {
    id: number;
    code: string;
    name: string;
    tax_type: string;
    regime: string;
    rate_basis_points: number;
    threshold_cents: number | null;
    recoverable: boolean;
    valid_from: string;
    valid_to: string | null;
    is_active: boolean;
};

type ExchangeRate = {
    id: number;
    currency: string;
    quote_currency: string;
    rate: string;
    source: string;
    effective_on: string;
    reference: string | null;
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

const today = new Date().toLocaleDateString('en-CA');

export default function FiscalSettings({
    company,
    profile,
    rules,
    exchangeRates,
    canManage,
}: {
    company: Company;
    profile: FiscalProfile;
    rules: TaxRule[];
    exchangeRates: ExchangeRate[];
    canManage: boolean;
}) {
    const endpoint = `/accounting/companies/${company.public_id}/fiscal-settings`;
    const profileForm = useForm({ ...profile });
    const ruleForm = useForm({
        code: '',
        name: '',
        tax_type: 'vat',
        regime: profile.vat_regime,
        rate: '12.00',
        threshold: '',
        recoverable: true,
        valid_from: today,
        valid_to: '',
        is_active: true,
    });
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
                            Declaración mensual de IVA
                        </p>
                        <strong className="mt-1 block text-lg">
                            14 de octubre de 2026
                        </strong>
                        <p className="mt-1 text-[10px] text-slate-500">
                            Formulario SAT-2237 · septiembre
                        </p>
                    </article>
                    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="grid size-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
                                <FileCheck2 className="size-5" />
                            </span>
                            <span className="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-semibold text-emerald-700">
                                Activo
                            </span>
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            Facturación electrónica
                        </p>
                        <strong className="mt-1 block text-lg">
                            FEL configurada
                        </strong>
                        <p className="mt-1 text-[10px] text-slate-500">
                            Ambiente simulado · NIT {company.tax_id}
                        </p>
                    </article>
                    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-center justify-between">
                            <span className="grid size-10 place-items-center rounded-xl bg-violet-50 text-violet-600">
                                <BadgeCheck className="size-5" />
                            </span>
                            <span className="text-lg font-bold text-emerald-600">
                                100%
                            </span>
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            Integridad de parámetros
                        </p>
                        <strong className="mt-1 block text-lg">
                            Configuración completa
                        </strong>
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <span className="block h-full w-full bg-emerald-500" />
                        </div>
                    </article>
                </section>

                <div className="grid gap-6 xl:grid-cols-[1.05fr_.95fr]">
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
                            <div className="mt-5 overflow-hidden rounded-xl border border-slate-200">
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
                                                    {rate.effective_on}
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
                                                    {rate.source}
                                                </td>
                                            </tr>
                                        ))}
                                        {exchangeRates.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={4}
                                                    className="p-4 text-center text-slate-500"
                                                >
                                                    Registra la tasa publicada
                                                    por BANGUAT antes de
                                                    contabilizar moneda
                                                    extranjera.
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
                    <Card title="Reglas fiscales y vigencias">
                        <div className="grid gap-5 xl:grid-cols-[1.25fr_.75fr]">
                            <div className="overflow-x-auto rounded-xl border border-slate-200">
                                <table className="w-full min-w-[760px] text-left text-sm">
                                    <thead className="bg-[#faf4f4] text-xs text-slate-600 uppercase">
                                        <tr>
                                            <th className="p-3">Impuesto</th>
                                            <th className="p-3">Régimen</th>
                                            <th className="p-3">Tasa</th>
                                            <th className="p-3">Umbral</th>
                                            <th className="p-3">Vigencia</th>
                                            <th className="p-3">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rules.map((rule) => (
                                            <tr
                                                key={rule.id}
                                                className="border-t border-slate-100"
                                            >
                                                <td className="p-3">
                                                    <p className="font-semibold">
                                                        {rule.name}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {rule.code} ·{' '}
                                                        {labels[rule.tax_type]}
                                                    </p>
                                                </td>
                                                <td className="p-3">
                                                    {labels[rule.regime] ??
                                                        rule.regime}
                                                </td>
                                                <td className="p-3 font-semibold tabular-nums">
                                                    {(
                                                        rule.rate_basis_points /
                                                        100
                                                    ).toFixed(2)}
                                                    %
                                                </td>
                                                <td className="p-3">
                                                    {rule.threshold_cents
                                                        ? money(
                                                              rule.threshold_cents,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="p-3">
                                                    {rule.valid_from}
                                                    <br />
                                                    <span className="text-xs text-slate-500">
                                                        a{' '}
                                                        {rule.valid_to ??
                                                            'sin vencimiento'}
                                                    </span>
                                                </td>
                                                <td className="p-3">
                                                    <span
                                                        className={`rounded-full px-2 py-1 text-xs font-semibold ${rule.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}
                                                    >
                                                        {rule.is_active
                                                            ? 'Activa'
                                                            : 'Inactiva'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <form
                                className="rounded-xl border border-[#eadfdf] bg-[#fffafa] p-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    ruleForm.post(`${endpoint}/tax-rules`, {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            ruleForm.reset(
                                                'code',
                                                'name',
                                                'threshold',
                                                'valid_to',
                                            ),
                                    });
                                }}
                            >
                                <h3 className="flex items-center gap-2 font-semibold">
                                    <Plus className="size-4 text-[#76080e]" />{' '}
                                    Nueva vigencia
                                </h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    No sobrescribe tasas históricas. Agrega una
                                    regla con su fecha de inicio.
                                </p>
                                <Errors errors={ruleForm.errors} />
                                <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                    <input
                                        className={field}
                                        placeholder="CÓDIGO_REGLA"
                                        value={ruleForm.data.code}
                                        onChange={(event) =>
                                            ruleForm.setData(
                                                'code',
                                                event.target.value
                                                    .toUpperCase()
                                                    .replace(/[^A-Z0-9_]/g, ''),
                                            )
                                        }
                                        disabled={!canManage}
                                    />
                                    <input
                                        className={field}
                                        placeholder="Nombre de la regla"
                                        value={ruleForm.data.name}
                                        onChange={(event) =>
                                            ruleForm.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        disabled={!canManage}
                                    />
                                    <div className="grid grid-cols-2 gap-3">
                                        <select
                                            className={field}
                                            value={ruleForm.data.tax_type}
                                            onChange={(event) =>
                                                ruleForm.setData(
                                                    'tax_type',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={!canManage}
                                        >
                                            <option value="vat">IVA</option>
                                            <option value="income_tax">
                                                ISR
                                            </option>
                                            <option value="withholding">
                                                Retención
                                            </option>
                                        </select>
                                        <input
                                            className={field}
                                            placeholder="Tasa %"
                                            value={ruleForm.data.rate}
                                            onChange={(event) =>
                                                ruleForm.setData(
                                                    'rate',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={!canManage}
                                        />
                                    </div>
                                    <input
                                        className={field}
                                        placeholder="Régimen (ej. general)"
                                        value={ruleForm.data.regime}
                                        onChange={(event) =>
                                            ruleForm.setData(
                                                'regime',
                                                event.target.value
                                                    .toLowerCase()
                                                    .replace(/[^a-z0-9_]/g, ''),
                                            )
                                        }
                                        disabled={!canManage}
                                    />
                                    <div className="grid grid-cols-2 gap-3">
                                        <input
                                            type="date"
                                            className={field}
                                            value={ruleForm.data.valid_from}
                                            onChange={(event) =>
                                                ruleForm.setData(
                                                    'valid_from',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={!canManage}
                                        />
                                        <input
                                            type="date"
                                            className={field}
                                            value={ruleForm.data.valid_to}
                                            onChange={(event) =>
                                                ruleForm.setData(
                                                    'valid_to',
                                                    event.target.value,
                                                )
                                            }
                                            disabled={!canManage}
                                        />
                                    </div>
                                    {canManage && (
                                        <button
                                            className={primary}
                                            disabled={ruleForm.processing}
                                        >
                                            <CalendarClock className="size-4" />{' '}
                                            Registrar vigencia
                                        </button>
                                    )}
                                </div>
                            </form>
                        </div>
                    </Card>
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
