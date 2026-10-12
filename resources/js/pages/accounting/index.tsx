import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    Building2,
    Plus,
    Search,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import {
    Card,
    Errors,
    field,
    Pager,
    primary,
    secondary,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import { index, show, storeCompany } from '@/routes/accounting';

type Company = {
    public_id: string;
    name: string;
    tax_id: string;
    office_name: string;
    currency: string;
};
type Props = {
    organizationName: string;
    canManage: boolean;
    companies: Page<Company>;
    offices: { id: number; name: string }[];
    search: string;
    notice: string | null;
};

export default function AccountingIndex({
    organizationName,
    canManage,
    companies,
    offices,
    search,
    notice,
}: Props) {
    const [adding, setAdding] = useState(false);
    const readOnly = Boolean(usePage().props.executiveReadOnly);
    const form = useForm({
        name: '',
        tax_id: '',
        email: '',
        office_id: String(offices[0]?.id ?? ''),
    });

    return (
        <>
            <Head title="Empresas y contabilidad" />
            <main className="min-h-full space-y-6 bg-[#faf8f7] p-4 text-[#142135] sm:p-8">
                <header className="relative overflow-hidden rounded-3xl bg-[#640000] p-7 text-white sm:p-9">
                    <div className="pointer-events-none absolute -top-20 -right-10 size-72 rounded-full border border-white/10" />
                    <p className="text-xs font-semibold tracking-[.2em] text-rose-200">
                        ATLANTIA FINANCE / CONTABILIDAD
                    </p>
                    <h1 className="mt-3 text-3xl font-semibold sm:text-4xl">
                        Cada empresa, sus propios libros.
                    </h1>
                    <p className="mt-3 max-w-2xl text-rose-100">
                        Administra la contabilidad de tus clientes en{' '}
                        {organizationName}, con acceso por asignación y
                        trazabilidad.
                    </p>
                    <div className="mt-6 flex items-center gap-2 text-sm text-rose-200">
                        <ShieldCheck size={18} /> Datos separados por empresa ·
                        Moneda GTQ
                    </div>
                </header>
                {notice && (
                    <p
                        role="status"
                        className="rounded-xl bg-emerald-50 p-4 text-emerald-800"
                    >
                        {notice}
                    </p>
                )}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <form action={index.url()} className="flex flex-1 gap-2">
                        <label className="sr-only" htmlFor="company-search">
                            Buscar empresa o NIT
                        </label>
                        <input
                            id="company-search"
                            name="search"
                            defaultValue={search}
                            placeholder="Buscar empresa o NIT…"
                            className={`${field} mt-0 max-w-md`}
                        />
                        <button className={secondary} aria-label="Buscar">
                            <Search size={18} />
                        </button>
                    </form>
                    {canManage && (
                        <button
                            className={primary}
                            onClick={() => setAdding(!adding)}
                        >
                            <Plus size={18} /> Nueva empresa
                        </button>
                    )}
                </div>
                {adding && canManage && (
                    <Card title="Registrar empresa cliente">
                        <p className="mb-4 text-sm text-slate-500">
                            Cada empresa utiliza un expediente del plan. El NIT
                            se registra como identificador; esta pantalla no
                            valida su inscripción fiscal.
                        </p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(storeCompany.url());
                            }}
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <label className="text-sm font-medium">
                                    Razón social
                                    <input
                                        required
                                        maxLength={150}
                                        className={field}
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                    />
                                </label>
                                <label className="text-sm font-medium">
                                    NIT / identificador fiscal
                                    <input
                                        required
                                        maxLength={30}
                                        className={field}
                                        value={form.data.tax_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'tax_id',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="text-sm font-medium">
                                    Correo de contacto (opcional)
                                    <input
                                        type="email"
                                        className={field}
                                        value={form.data.email}
                                        onChange={(e) =>
                                            form.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="text-sm font-medium">
                                    Oficina responsable
                                    <select
                                        required
                                        className={field}
                                        value={form.data.office_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'office_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            Selecciona una oficina
                                        </option>
                                        {offices.map((office) => (
                                            <option
                                                key={office.id}
                                                value={office.id}
                                            >
                                                {office.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <Errors errors={form.errors} />
                            <div className="mt-5 flex gap-3">
                                <button
                                    disabled={
                                        form.processing || !offices.length
                                    }
                                    className={primary}
                                >
                                    {form.processing
                                        ? 'Guardando…'
                                        : 'Crear y configurar'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setAdding(false)}
                                    className={secondary}
                                >
                                    Cancelar
                                </button>
                            </div>
                            {!offices.length && (
                                <p className="mt-3 text-sm text-amber-800">
                                    Completa primero la configuración de tu
                                    oficina en Mi organización.
                                </p>
                            )}
                        </form>
                    </Card>
                )}
                {companies.data.length ? (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {companies.data.map((company) => (
                            <CompanyCard
                                key={company.public_id}
                                href={
                                    readOnly
                                        ? null
                                        : show.url(company.public_id)
                                }
                            >
                                <div className="flex justify-between">
                                    <span className="rounded-xl bg-[#f5e7e7] p-3 text-[#640000]">
                                        <Building2 size={23} />
                                    </span>
                                    {!readOnly && (
                                        <ArrowUpRight
                                            size={20}
                                            className="text-[#b67e7d]"
                                        />
                                    )}
                                </div>
                                <h2 className="mt-5 text-lg font-semibold">
                                    {company.name}
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    NIT {company.tax_id}
                                </p>
                                <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
                                    <span>{company.office_name}</span>
                                    <span className="rounded-full bg-slate-50 px-2 py-1">
                                        {company.currency}
                                    </span>
                                </div>
                            </CompanyCard>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-2xl border border-dashed border-[#dbc9c9] bg-white p-12 text-center">
                        <Building2
                            className="mx-auto mb-4 text-[#b67e7d]"
                            size={36}
                        />
                        <h2 className="text-xl font-semibold">
                            {search
                                ? 'Sin coincidencias'
                                : 'Tu cartera empieza aquí'}
                        </h2>
                        <p className="mt-2 text-slate-500">
                            {canManage
                                ? 'Registra una empresa para configurar cuentas, períodos y su primer asiento.'
                                : 'El propietario debe asignarte una empresa para que puedas consultar o preparar su contabilidad.'}
                        </p>
                    </div>
                )}
                <Pager page={companies} />
            </main>
        </>
    );
}

/** A company card; the supervising director sees the firm's clients but cannot open their books. */
function CompanyCard({
    href,
    children,
}: {
    href: string | null;
    children: React.ReactNode;
}) {
    const base = 'rounded-2xl border border-[#ece3e3] bg-white p-6 shadow-sm';

    return href === null ? (
        <div className={base}>{children}</div>
    ) : (
        <Link
            href={href}
            className={`group transition hover:border-[#b67e7d] hover:shadow-md ${base}`}
        >
            {children}
        </Link>
    );
}
