import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowUpRight, Building2, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import {
    Errors,
    field,
    Pager,
    primary,
    secondary,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
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
    canConsolidate?: boolean;
    companies: Page<Company>;
    offices: { id: number; name: string }[];
    search: string;
    notice: string | null;
    companyQuota?: { limit: number | null; used: number };
};

export default function AccountingIndex({
    organizationName,
    canManage,
    canConsolidate = false,
    companies,
    offices,
    search,
    notice,
    companyQuota,
}: Props) {
    const [adding, setAdding] = useState(false);
    const [limitReached, setLimitReached] = useState(false);
    const atLimit =
        companyQuota?.limit != null && companyQuota.used >= companyQuota.limit;
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
                <header>
                    <p className="text-xs font-bold tracking-[.22em] text-[#8a3b32]">
                        CONTABILIDAD
                    </p>
                    <h1 className="mt-1 text-3xl font-black text-[#061633]">
                        Empresas y contabilidad
                    </h1>
                    <p className="mt-2 max-w-3xl text-[#395277]">
                        Administra la contabilidad de tus clientes en{' '}
                        {organizationName}, con acceso por asignación y
                        trazabilidad. Datos separados por empresa · Moneda GTQ.
                    </p>
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
                    {canConsolidate && (
                        <Link
                            href="/accounting/consolidation"
                            className={secondary}
                        >
                            Consolidación de grupos
                        </Link>
                    )}
                    {canManage && (
                        <button
                            className={primary}
                            onClick={() =>
                                atLimit
                                    ? setLimitReached(true)
                                    : setAdding(!adding)
                            }
                        >
                            <Plus size={18} /> Nuevo cliente
                        </button>
                    )}
                </div>
                <Dialog open={limitReached} onOpenChange={setLimitReached}>
                    <DialogContent className="bg-white text-[#142135] sm:max-w-md">
                        <div className="mx-auto grid size-14 place-items-center rounded-full bg-red-50 text-[#640000]">
                            <Building2 className="size-7" />
                        </div>
                        <DialogTitle className="text-center">
                            Alcanzaste el límite de empresas
                        </DialogTitle>
                        <DialogDescription className="text-center">
                            Tu plan permite hasta {companyQuota?.limit} empresas
                            y ya tienes {companyQuota?.used} registradas. Para
                            seguir creciendo, amplía tu plan desde la sección de
                            suscripción.
                        </DialogDescription>
                        <div className="flex flex-wrap justify-center gap-2 pt-2">
                            <Link
                                href="/office/subscription"
                                className={primary}
                            >
                                Ver planes
                            </Link>
                            <button
                                type="button"
                                className={secondary}
                                onClick={() => setLimitReached(false)}
                            >
                                Entendido
                            </button>
                        </div>
                    </DialogContent>
                </Dialog>
                {canManage && (
                    <Dialog open={adding} onOpenChange={setAdding}>
                        <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
                            <div className="flex items-center gap-3">
                                <span className="grid size-11 place-items-center rounded-xl bg-[#f5e7e7] text-[#640000]">
                                    <Building2 size={22} />
                                </span>
                                <DialogTitle>
                                    Registrar empresa cliente
                                </DialogTitle>
                            </div>
                            <DialogDescription>
                                Cada empresa utiliza un expediente del plan. El
                                NIT se registra como identificador; esta
                                pantalla no valida su inscripción fiscal.
                            </DialogDescription>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post(storeCompany.url(), {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            form.reset(
                                                'name',
                                                'tax_id',
                                                'email',
                                            );
                                            setAdding(false);
                                        },
                                    });
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
                                                form.setData(
                                                    'name',
                                                    e.target.value,
                                                )
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
                                    {offices.length > 1 && (
                                        <label className="text-sm font-medium">
                                            Oficina que lo atiende
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
                                    )}
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
                        </DialogContent>
                    </Dialog>
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
