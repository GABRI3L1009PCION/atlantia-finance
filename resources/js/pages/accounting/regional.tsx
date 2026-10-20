import { router, useForm } from '@inertiajs/react';
import { Globe2, Plus, Trash2 } from 'lucide-react';
import {
    Badge,
    cell,
    Empty,
    head,
    Label,
    PageShell,
} from '@/components/accounting-page';
import type { Company } from '@/components/accounting-section';
import {
    Card,
    Errors,
    field,
    primary,
    secondary,
} from '@/components/accounting-ui';

type Tax = {
    code: string;
    name: string;
    kind: 'vat' | 'income_tax';
    rate_bp: number;
    legal_basis?: string | null;
};
type Country = {
    name: string;
    currency: string;
    timezone: string;
    authority: string;
    legislation: string[];
    templates: string[];
    taxes: Tax[];
};
type Regional = {
    country_code: string;
    country_name: string;
    authority: string | null;
    legislation: string[];
    base_currency: string;
    secondary_currencies: string[];
    timezone: string;
    template: string;
    tax_structure: Tax[];
    tax_structure_customized: boolean;
    exchange_rates: {
        currency: string;
        quote: string;
        rate: string;
        on: string;
        source: string;
    }[];
    countries: Record<string, Country>;
    currencies: Record<string, string>;
    templates: {
        key: string;
        label: string;
        accounts: number;
        missing: number;
    }[];
    has_entries: boolean;
};

export default function RegionalPage({
    company,
    regional,
    canManage,
}: {
    company: Company;
    regional: Regional;
    canManage: boolean;
}) {
    const base = `/accounting/companies/${company.public_id}/regional`;
    const form = useForm({
        country_code: regional.country_code,
        base_currency: regional.base_currency,
        secondary_currencies: regional.secondary_currencies,
        template: regional.template,
        tax_structure: regional.tax_structure.map((tax) => ({
            code: tax.code,
            name: tax.name,
            kind: tax.kind,
            rate: String(tax.rate_bp / 100),
            legal_basis: tax.legal_basis ?? '',
        })),
    });
    const country = regional.countries[form.data.country_code];
    const pickCountry = (code: string) => {
        const next = regional.countries[code];
        form.setData({
            ...form.data,
            country_code: code,
            base_currency: regional.has_entries
                ? form.data.base_currency
                : next.currency,
            template: next.templates.includes(form.data.template)
                ? form.data.template
                : next.templates[0],
            tax_structure: next.taxes.map((tax) => ({
                code: tax.code,
                name: tax.name,
                kind: tax.kind,
                rate: String(tax.rate_bp / 100),
                legal_basis: tax.legal_basis ?? '',
            })),
        });
    };
    const toggleSecondary = (code: string) =>
        form.setData(
            'secondary_currencies',
            form.data.secondary_currencies.includes(code)
                ? form.data.secondary_currencies.filter((item) => item !== code)
                : [...form.data.secondary_currencies, code],
        );
    const setTax = (index: number, key: string, value: string) =>
        form.setData(
            'tax_structure',
            form.data.tax_structure.map((tax, i) =>
                i === index ? { ...tax, [key]: value } : tax,
            ),
        );

    return (
        <PageShell
            company={company}
            title="Configuración regional"
            subtitle="País, monedas, impuestos y plantilla contable"
            icon={Globe2}
        >
            <form
                className="space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(base, { preserveScroll: true });
                }}
            >
                <Card title="País y legislación">
                    <div className="grid gap-4 md:grid-cols-3">
                        <Label text="País">
                            <select
                                className={field}
                                disabled={!canManage}
                                value={form.data.country_code}
                                onChange={(e) => pickCountry(e.target.value)}
                            >
                                {Object.entries(regional.countries).map(
                                    ([code, item]) => (
                                        <option key={code} value={code}>
                                            {item.name}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Label>
                        <Label text="Moneda base">
                            <select
                                className={field}
                                disabled={!canManage || regional.has_entries}
                                value={form.data.base_currency}
                                onChange={(e) =>
                                    form.setData(
                                        'base_currency',
                                        e.target.value,
                                    )
                                }
                            >
                                {Object.entries(regional.currencies).map(
                                    ([code, name]) => (
                                        <option key={code} value={code}>
                                            {code} · {name}
                                        </option>
                                    ),
                                )}
                            </select>
                            {regional.has_entries && (
                                <span className="mt-1 block text-[11px] font-normal text-slate-500 normal-case">
                                    Bloqueada: la empresa ya tiene asientos.
                                </span>
                            )}
                        </Label>
                        <Label text="Plantilla contable">
                            <select
                                className={field}
                                disabled={!canManage}
                                value={form.data.template}
                                onChange={(e) =>
                                    form.setData('template', e.target.value)
                                }
                            >
                                {regional.templates.map((template) => (
                                    <option
                                        key={template.key}
                                        value={template.key}
                                    >
                                        {template.label}
                                    </option>
                                ))}
                            </select>
                        </Label>
                    </div>
                    {country && (
                        <div className="mt-4 rounded-xl bg-[#fbf8f8] p-4 text-sm">
                            <p>
                                <strong>Autoridad fiscal:</strong>{' '}
                                {country.authority} ·{' '}
                                <strong>Zona horaria:</strong>{' '}
                                {country.timezone}
                            </p>
                            <p className="mt-1 text-slate-600">
                                <strong>Legislación:</strong>{' '}
                                {country.legislation.join(' · ')}
                            </p>
                        </div>
                    )}
                </Card>

                <Card title="Monedas secundarias">
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(regional.currencies)
                            .filter(
                                ([code]) => code !== form.data.base_currency,
                            )
                            .map(([code, name]) => (
                                <label
                                    key={code}
                                    className={`cursor-pointer rounded-full px-3 py-1.5 text-sm ring-1 ${form.data.secondary_currencies.includes(code) ? 'bg-[#640000] text-white ring-[#640000]' : 'bg-white text-slate-700 ring-slate-200'}`}
                                >
                                    <input
                                        type="checkbox"
                                        className="sr-only"
                                        disabled={!canManage}
                                        checked={form.data.secondary_currencies.includes(
                                            code,
                                        )}
                                        onChange={() => toggleSecondary(code)}
                                    />
                                    {code} · {name}
                                </label>
                            ))}
                    </div>
                    {regional.exchange_rates.length > 0 && (
                        <table className="mt-4 w-full max-w-xl text-sm">
                            <thead>
                                <tr>
                                    {[
                                        'Moneda',
                                        'Tasa',
                                        'Vigente desde',
                                        'Fuente',
                                    ].map((title) => (
                                        <th key={title} className={head}>
                                            {title}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {regional.exchange_rates.map((rate) => (
                                    <tr
                                        key={rate.currency}
                                        className="border-t border-slate-100"
                                    >
                                        <td className={cell}>
                                            {rate.currency}/{rate.quote}
                                        </td>
                                        <td className={cell}>{rate.rate}</td>
                                        <td className={cell}>{rate.on}</td>
                                        <td className={cell}>{rate.source}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>

                <Card title="Estructura impositiva">
                    {form.data.tax_structure.length === 0 ? (
                        <Empty>
                            Este país no tiene impuestos en el catálogo.
                        </Empty>
                    ) : (
                        <div className="space-y-3">
                            {form.data.tax_structure.map((tax, index) => (
                                <div
                                    key={index}
                                    className="grid gap-2 md:grid-cols-[150px_1fr_130px_110px_1fr_auto] md:items-end"
                                >
                                    <Label text="Código">
                                        <input
                                            className={field}
                                            disabled={!canManage}
                                            value={tax.code}
                                            onChange={(e) =>
                                                setTax(
                                                    index,
                                                    'code',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                    <Label text="Nombre">
                                        <input
                                            className={field}
                                            disabled={!canManage}
                                            value={tax.name}
                                            onChange={(e) =>
                                                setTax(
                                                    index,
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                    <Label text="Tipo">
                                        <select
                                            className={field}
                                            disabled={!canManage}
                                            value={tax.kind}
                                            onChange={(e) =>
                                                setTax(
                                                    index,
                                                    'kind',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="vat">
                                                IVA / ventas
                                            </option>
                                            <option value="income_tax">
                                                Renta
                                            </option>
                                        </select>
                                    </Label>
                                    <Label text="Tasa %">
                                        <input
                                            className={field}
                                            disabled={!canManage}
                                            inputMode="decimal"
                                            value={tax.rate}
                                            onChange={(e) =>
                                                setTax(
                                                    index,
                                                    'rate',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                    <Label text="Base legal">
                                        <input
                                            className={field}
                                            disabled={!canManage}
                                            value={tax.legal_basis}
                                            onChange={(e) =>
                                                setTax(
                                                    index,
                                                    'legal_basis',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Label>
                                    {canManage && (
                                        <button
                                            type="button"
                                            aria-label="Quitar impuesto"
                                            onClick={() =>
                                                form.setData(
                                                    'tax_structure',
                                                    form.data.tax_structure.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="mb-3 size-4 text-red-700" />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                    {canManage && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                className={secondary}
                                onClick={() =>
                                    form.setData('tax_structure', [
                                        ...form.data.tax_structure,
                                        {
                                            code: '',
                                            name: '',
                                            kind: 'vat',
                                            rate: '0',
                                            legal_basis: '',
                                        },
                                    ])
                                }
                            >
                                <Plus className="size-4" /> Agregar impuesto
                            </button>
                            {regional.tax_structure_customized && (
                                <Badge tone="amber">
                                    Estructura personalizada
                                </Badge>
                            )}
                        </div>
                    )}
                    <Errors errors={form.errors} />
                </Card>

                {canManage && (
                    <button className={primary} disabled={form.processing}>
                        Guardar configuración regional
                    </button>
                )}
            </form>

            {canManage && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <Card title="Catálogo de cuentas">
                        <p className="mb-3 text-sm text-slate-600">
                            Agrega al catálogo las cuentas de la plantilla que
                            aún no existen. Las cuentas existentes no se
                            modifican.
                        </p>
                        <div className="space-y-2">
                            {regional.templates.map((template) => (
                                <div
                                    key={template.key}
                                    className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 text-sm"
                                >
                                    <span>
                                        {template.label}{' '}
                                        <span className="text-slate-500">
                                            · {template.missing} de{' '}
                                            {template.accounts} por agregar
                                        </span>
                                    </span>
                                    <button
                                        className={secondary}
                                        disabled={template.missing === 0}
                                        onClick={() =>
                                            router.post(
                                                `${base}/template`,
                                                { template: template.key },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Instalar
                                    </button>
                                </div>
                            ))}
                        </div>
                    </Card>
                    <Card title="Impuestos fiscales">
                        <p className="mb-3 text-sm text-slate-600">
                            Crea o versiona los impuestos de la empresa según la
                            estructura guardada, con vigencia desde hoy.
                        </p>
                        <button
                            className={primary}
                            onClick={() =>
                                router.post(
                                    `${base}/taxes`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Sincronizar impuestos
                        </button>
                    </Card>
                </div>
            )}
        </PageShell>
    );
}
