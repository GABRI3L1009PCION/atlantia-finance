import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    CircleDollarSign,
    Download,
    EllipsisVertical,
    FileSpreadsheet,
    FileText,
    Mail,
    Pencil,
    Phone,
    Plus,
    Search,
    Upload,
    UsersRound,
    WalletCards,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import type { Company } from '@/components/accounting-section';
import {
    Errors,
    field,
    money,
    Pager,
    primary,
    secondary,
} from '@/components/accounting-ui';
import type { Page } from '@/components/accounting-ui';
import * as routes from '@/routes/accounting';

type PurchaseDocument = {
    id: number;
    public_id: string;
    number: number | null;
    external_reference: string | null;
    status: string;
    document_date: string;
    due_on: string;
    total_cents: number | string;
    paid_cents: number | string;
};

type Aging = {
    current: number;
    days_1_30: number;
    days_31_60: number;
    days_60_plus: number;
};

type Supplier = {
    id: number;
    name: string;
    tax_id: string | null;
    email: string | null;
    phone: string | null;
    payment_terms_days: number | null;
    created_at: string;
    document_count: number | string;
    purchased_cents: number | string;
    paid_cents: number | string;
    outstanding_cents: number | string;
    overdue_cents: number | string;
    upcoming_cents: number | string;
    next_due_on: string | null;
    first_unpaid_document_public_id: string | null;
    aging: Aging;
    recent_documents: PurchaseDocument[];
};

type Summary = {
    active: number;
    pending: number;
    outstanding: number;
    overdue: number;
    upcoming: number;
    advances: number;
};

const numeric = (value: number | string) => Number(value);

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function situation(supplier: Supplier): 'Al día' | 'Pendiente' | 'Vencido' {
    if (numeric(supplier.overdue_cents) > 0) {
        return 'Vencido';
    }

    if (numeric(supplier.outstanding_cents) > 0) {
        return 'Pendiente';
    }

    return 'Al día';
}

function situationClass(value: ReturnType<typeof situation>): string {
    if (value === 'Vencido') {
        return 'bg-rose-50 text-rose-700';
    }

    if (value === 'Pendiente') {
        return 'bg-amber-50 text-amber-700';
    }

    return 'bg-emerald-50 text-emerald-700';
}

function documentLabel(document: PurchaseDocument): string {
    return (
        document.external_reference ||
        (document.number
            ? `CP-${String(document.number).padStart(6, '0')}`
            : 'BORRADOR')
    );
}

export default function Suppliers({
    company,
    suppliers,
    filters,
    summary,
    canWrite,
    today,
}: {
    company: Company;
    suppliers: Page<Supplier>;
    filters: { search: string; situation: string };
    summary: Summary;
    canWrite: boolean;
    today: string;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(
        suppliers.data[0]?.id ?? null,
    );
    const [editor, setEditor] = useState<'create' | 'edit' | null>(null);
    const search = useForm({
        search: filters.search ?? '',
        situation: filters.situation ?? 'all',
    });
    const supplierForm = useForm({
        name: '',
        tax_id: '',
        email: '',
        phone: '',
        payment_terms_days: '',
    });
    const selected = useMemo(
        () =>
            suppliers.data.find((supplier) => supplier.id === selectedId) ??
            suppliers.data[0] ??
            null,
        [selectedId, suppliers.data],
    );

    const applyFilters = (event?: FormEvent) => {
        event?.preventDefault();
        router.get(
            routes.suppliers.url(company.public_id),
            {
                search: search.data.search || undefined,
                situation:
                    search.data.situation === 'all'
                        ? undefined
                        : search.data.situation,
            },
            { preserveState: true, replace: true },
        );
    };
    const openCreate = () => {
        supplierForm.reset();
        supplierForm.clearErrors();
        setEditor('create');
    };
    const openEdit = (supplier: Supplier) => {
        supplierForm.setData({
            name: supplier.name,
            tax_id: supplier.tax_id ?? '',
            email: supplier.email ?? '',
            phone: supplier.phone ?? '',
            payment_terms_days:
                supplier.payment_terms_days === null
                    ? ''
                    : String(supplier.payment_terms_days),
        });
        supplierForm.clearErrors();
        setEditor('edit');
    };

    return (
        <div className="min-h-full bg-[#f7f8fb] p-3 text-[#142135] sm:p-5">
            <Head title="Proveedores" />
            <div className="mx-auto max-w-[1680px] space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <span className="grid size-12 place-items-center rounded-2xl bg-[#7d0808] text-white shadow-lg shadow-red-950/15">
                            <UsersRound className="size-6" />
                        </span>
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Proveedores
                            </h1>
                            <p className="mt-1 text-sm text-slate-500">
                                Gestión de proveedores, historial de compras y
                                cuentas por pagar
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canWrite && (
                            <button
                                type="button"
                                className={primary}
                                onClick={openCreate}
                            >
                                <Plus className="size-4" /> Nuevo proveedor
                            </button>
                        )}
                        <button
                            type="button"
                            className={secondary}
                            disabled
                            title="Disponible cuando se defina la plantilla de proveedores"
                        >
                            <Upload className="size-4" /> Importar
                        </button>
                        <a
                            className={secondary}
                            href={routes.suppliersExport.url(
                                company.public_id,
                                { query: { format: 'xlsx' } },
                            )}
                        >
                            <FileSpreadsheet className="size-4 text-emerald-700" />{' '}
                            Excel
                        </a>
                        <a
                            className={secondary}
                            href={routes.suppliersExport.url(
                                company.public_id,
                                { query: { format: 'pdf' } },
                            )}
                        >
                            <Download className="size-4 text-red-700" /> PDF
                        </a>
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => window.print()}
                        >
                            Más acciones <ChevronDown className="size-4" />
                        </button>
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    {[
                        {
                            label: 'Proveedores activos',
                            value: summary.active,
                            hint: 'Registrados en la empresa',
                            icon: UsersRound,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                        {
                            label: 'Con saldo pendiente',
                            value: summary.pending,
                            hint: `${summary.active ? Math.round((summary.pending / summary.active) * 1000) / 10 : 0}% del total`,
                            icon: FileText,
                            tone: 'bg-blue-50 text-blue-700',
                        },
                        {
                            label: 'Total por pagar',
                            value: money(summary.outstanding),
                            hint: 'Cartera vigente',
                            icon: CircleDollarSign,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                        {
                            label: 'Saldo vencido',
                            value: money(summary.overdue),
                            hint: 'Requiere seguimiento',
                            icon: AlertTriangle,
                            tone: 'bg-rose-50 text-rose-700',
                        },
                        {
                            label: 'Próximos 7 días',
                            value: money(summary.upcoming),
                            hint: 'Vencimientos cercanos',
                            icon: CalendarDays,
                            tone: 'bg-violet-50 text-violet-700',
                        },
                        {
                            label: 'Anticipos disponibles',
                            value: money(summary.advances),
                            hint: 'Módulo pendiente',
                            icon: CheckCircle2,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                    ].map((metric) => (
                        <article
                            key={metric.label}
                            className="flex min-h-28 items-center gap-3 rounded-2xl border border-[#e4e8ef] bg-white p-4 shadow-[0_7px_20px_rgba(31,38,55,.05)]"
                        >
                            <span
                                className={`grid size-11 shrink-0 place-items-center rounded-full ${metric.tone}`}
                            >
                                <metric.icon size={20} />
                            </span>
                            <div className="min-w-0">
                                <p className="text-[11px] font-medium text-slate-500">
                                    {metric.label}
                                </p>
                                <strong className="mt-1 block truncate text-lg">
                                    {metric.value}
                                </strong>
                                <p className="mt-1 text-[10px] text-slate-400">
                                    {metric.hint}
                                </p>
                            </div>
                        </article>
                    ))}
                </section>

                <div className="grid items-start gap-4 2xl:grid-cols-[minmax(0,1fr)_480px]">
                    <section className="min-w-0 overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_22px_rgba(31,38,55,.05)]">
                        <form
                            className="grid gap-3 border-b border-[#e8ebf1] p-4 lg:grid-cols-[minmax(230px,1fr)_145px_145px_180px_130px]"
                            onSubmit={applyFilters}
                        >
                            <label className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    className="h-10 w-full rounded-xl border border-[#dfe3ea] pr-3 pl-10 text-sm outline-none focus:border-[#8f1f30]"
                                    value={search.data.search}
                                    onChange={(event) =>
                                        search.setData(
                                            'search',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Proveedor, NIT, correo..."
                                />
                            </label>
                            <select
                                className="h-10 rounded-xl border border-[#dfe3ea] px-3 text-sm"
                                defaultValue="active"
                                aria-label="Estado"
                            >
                                <option value="active">Activos</option>
                            </select>
                            <select
                                className="h-10 rounded-xl border border-[#dfe3ea] px-3 text-sm"
                                disabled
                                defaultValue="all"
                                aria-label="Categoría"
                            >
                                <option value="all">Categoría: todas</option>
                            </select>
                            <select
                                className="h-10 rounded-xl border border-[#dfe3ea] px-3 text-sm"
                                value={search.data.situation}
                                onChange={(event) =>
                                    search.setData(
                                        'situation',
                                        event.target.value,
                                    )
                                }
                                aria-label="Situación de pago"
                            >
                                <option value="all">Toda situación</option>
                                <option value="current">Al día</option>
                                <option value="pending">Pendiente</option>
                                <option value="overdue">Vencido</option>
                            </select>
                            <button className={primary}>Filtrar</button>
                        </form>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[980px] text-left text-xs">
                                <thead className="bg-[#fbfcfd] text-[10px] tracking-wide text-slate-500 uppercase">
                                    <tr>
                                        <th className="p-4">Proveedor</th>
                                        <th className="p-4">NIT</th>
                                        <th className="p-4">Condición</th>
                                        <th className="p-4 text-right">
                                            Compras
                                        </th>
                                        <th className="p-4 text-right">
                                            Por pagar
                                        </th>
                                        <th className="p-4 text-right">
                                            Vencido
                                        </th>
                                        <th className="p-4">
                                            Próx. vencimiento
                                        </th>
                                        <th className="p-4">Estado</th>
                                        <th className="p-4" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {suppliers.data.map((supplier, index) => {
                                        const state = situation(supplier);
                                        const colors = [
                                            'bg-[#8f1f30]',
                                            'bg-emerald-600',
                                            'bg-violet-600',
                                            'bg-orange-500',
                                            'bg-cyan-600',
                                            'bg-pink-500',
                                            'bg-blue-600',
                                        ];

                                        return (
                                            <tr
                                                key={supplier.id}
                                                onClick={() =>
                                                    setSelectedId(supplier.id)
                                                }
                                                className={`cursor-pointer border-t border-[#edf0f4] transition hover:bg-[#fbf7f7] ${selected?.id === supplier.id ? 'bg-[#fdf5f5]' : ''}`}
                                            >
                                                <td className="p-4">
                                                    <div className="flex items-center gap-3">
                                                        <span
                                                            className={`grid size-8 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white ${colors[index % colors.length]}`}
                                                        >
                                                            {initials(
                                                                supplier.name,
                                                            )}
                                                        </span>
                                                        <div className="min-w-0">
                                                            <p className="truncate font-semibold">
                                                                {supplier.name}
                                                            </p>
                                                            <p className="truncate text-[10px] text-slate-400">
                                                                {supplier.email ||
                                                                    'Correo no registrado'}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="p-4">
                                                    {supplier.tax_id || 'C/F'}
                                                </td>
                                                <td className="p-4">
                                                    {numeric(
                                                        supplier.outstanding_cents,
                                                    ) > 0
                                                        ? 'Crédito'
                                                        : 'Contado'}
                                                </td>
                                                <td className="p-4 text-right font-medium">
                                                    {money(
                                                        supplier.purchased_cents,
                                                    )}
                                                </td>
                                                <td className="p-4 text-right font-medium text-rose-700">
                                                    {money(
                                                        supplier.outstanding_cents,
                                                    )}
                                                </td>
                                                <td className="p-4 text-right font-medium text-rose-700">
                                                    {money(
                                                        supplier.overdue_cents,
                                                    )}
                                                </td>
                                                <td className="p-4">
                                                    {supplier.next_due_on ||
                                                        '—'}
                                                </td>
                                                <td className="p-4">
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${situationClass(state)}`}
                                                    >
                                                        {state}
                                                    </span>
                                                </td>
                                                <td className="p-4">
                                                    <EllipsisVertical className="size-4 text-slate-400" />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {!suppliers.data.length && (
                            <div className="grid min-h-60 place-items-center p-8 text-center">
                                <div>
                                    <UsersRound className="mx-auto size-10 text-slate-300" />
                                    <p className="mt-3 font-semibold">
                                        No se encontraron proveedores
                                    </p>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Limpia los filtros o registra el primer
                                        proveedor.
                                    </p>
                                </div>
                            </div>
                        )}
                        <div className="px-4 pb-4">
                            <Pager page={suppliers} />
                        </div>
                    </section>

                    <SupplierPanel
                        company={company}
                        supplier={selected}
                        today={today}
                        canWrite={canWrite}
                        onEdit={openEdit}
                    />
                </div>
            </div>

            {editor && (
                <div
                    className="fixed inset-0 z-[100] flex items-center justify-center bg-[#07101f]/70 p-4 backdrop-blur-sm"
                    role="dialog"
                    aria-modal="true"
                    onMouseDown={(event) =>
                        event.currentTarget === event.target && setEditor(null)
                    }
                >
                    <section className="w-full max-w-xl overflow-hidden rounded-3xl bg-white shadow-2xl">
                        <header className="flex items-start justify-between border-b p-6">
                            <div>
                                <p className="text-xs font-semibold tracking-[.18em] text-[#8f1f30] uppercase">
                                    Atlantia Finance
                                </p>
                                <h2 className="mt-1 text-2xl font-semibold">
                                    {editor === 'create'
                                        ? 'Nuevo proveedor'
                                        : 'Editar proveedor'}
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    Datos fiscales y de contacto del proveedor.
                                </p>
                            </div>
                            <button
                                type="button"
                                className="grid size-10 place-items-center rounded-xl border"
                                onClick={() => setEditor(null)}
                                aria-label="Cerrar"
                            >
                                <X className="size-5" />
                            </button>
                        </header>
                        <form
                            className="space-y-4 p-6"
                            onSubmit={(event) => {
                                event.preventDefault();

                                if (editor === 'edit' && selected) {
                                    supplierForm.patch(
                                        routes.purchaseSupplierUpdate.url([
                                            company.public_id,
                                            selected.id,
                                        ]),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setEditor(null),
                                        },
                                    );
                                } else {
                                    supplierForm.post(
                                        routes.purchaseSupplier.url(
                                            company.public_id,
                                        ),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                supplierForm.reset();
                                                setEditor(null);
                                            },
                                        },
                                    );
                                }
                            }}
                        >
                            <label className="block text-sm font-medium">
                                Nombre o razón social
                                <input
                                    required
                                    maxLength={150}
                                    className={field}
                                    value={supplierForm.data.name}
                                    onChange={(event) =>
                                        supplierForm.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <label className="text-sm font-medium">
                                    NIT / identificador
                                    <input
                                        maxLength={30}
                                        className={field}
                                        value={supplierForm.data.tax_id}
                                        onChange={(event) =>
                                            supplierForm.setData(
                                                'tax_id',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="text-sm font-medium">
                                    Teléfono
                                    <input
                                        maxLength={30}
                                        className={field}
                                        value={supplierForm.data.phone}
                                        onChange={(event) =>
                                            supplierForm.setData(
                                                'phone',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <label className="block text-sm font-medium">
                                Plazo de pago (días, opcional)
                                <input
                                    type="number"
                                    min={0}
                                    max={365}
                                    className={field}
                                    value={supplierForm.data.payment_terms_days}
                                    onChange={(event) =>
                                        supplierForm.setData(
                                            'payment_terms_days',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="block text-sm font-medium">
                                Correo
                                <input
                                    type="email"
                                    className={field}
                                    value={supplierForm.data.email}
                                    onChange={(event) =>
                                        supplierForm.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <Errors errors={supplierForm.errors} />
                            <div className="flex justify-end gap-3">
                                <button
                                    type="button"
                                    className={secondary}
                                    onClick={() => setEditor(null)}
                                >
                                    Cancelar
                                </button>
                                <button
                                    className={primary}
                                    disabled={supplierForm.processing}
                                >
                                    Guardar proveedor
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </div>
    );
}

function SupplierPanel({
    company,
    supplier,
    today,
    canWrite,
    onEdit,
}: {
    company: Company;
    supplier: Supplier | null;
    today: string;
    canWrite: boolean;
    onEdit: (supplier: Supplier) => void;
}) {
    if (!supplier) {
        return (
            <aside className="grid min-h-96 place-items-center rounded-2xl border border-[#e4e8ef] bg-white p-8 text-center">
                <div>
                    <Building2 className="mx-auto size-11 text-slate-300" />
                    <p className="mt-3 font-semibold">
                        Selecciona un proveedor
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        Su cartera e historial aparecerán aquí.
                    </p>
                </div>
            </aside>
        );
    }

    const state = situation(supplier);
    const totalAging = Object.values(supplier.aging).reduce(
        (sum, value) => sum + Number(value),
        0,
    );
    const daysUntilDue = supplier.next_due_on
        ? Math.ceil(
              (new Date(`${supplier.next_due_on}T00:00:00`).getTime() -
                  new Date(`${today}T00:00:00`).getTime()) /
                  86400000,
          )
        : null;

    return (
        <aside className="overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_22px_rgba(31,38,55,.05)]">
            <header className="border-b p-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="grid size-11 place-items-center rounded-full bg-[#8f1f30] text-xs font-bold text-white">
                            {initials(supplier.name)}
                        </span>
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="font-semibold">
                                    {supplier.name}
                                </h2>
                                <span className="rounded-full bg-emerald-50 px-2 py-1 text-[9px] font-semibold text-emerald-700">
                                    Activo
                                </span>
                            </div>
                            <p className="text-xs text-slate-500">
                                NIT: {supplier.tax_id || 'C/F'}
                            </p>
                        </div>
                    </div>
                    {canWrite && (
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => onEdit(supplier)}
                        >
                            <Pencil className="size-3" /> Editar
                        </button>
                    )}
                </div>
                <div className="mt-5 flex gap-5 overflow-x-auto border-b text-[10px] font-semibold text-slate-500">
                    <span className="border-b-2 border-[#8f1f30] pb-3 text-[#8f1f30]">
                        Resumen
                    </span>
                    <span>Compras</span>
                    <span>Cuentas por pagar</span>
                    <span>Pagos</span>
                    <span>Documentos</span>
                </div>
            </header>
            <div className="space-y-4 p-4">
                <section className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-xl border p-4 text-xs">
                        <h3 className="mb-3 font-semibold">
                            Información general
                        </h3>
                        <dl className="space-y-2 text-slate-600">
                            <div className="flex justify-between">
                                <dt>Nombre comercial</dt>
                                <dd className="font-semibold">
                                    {supplier.name}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Tipo</dt>
                                <dd className="font-semibold">Empresa</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Condición</dt>
                                <dd className="font-semibold">
                                    {numeric(supplier.outstanding_cents) > 0
                                        ? 'Crédito'
                                        : 'Contado'}
                                </dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Moneda</dt>
                                <dd className="font-semibold">GTQ</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt>Situación</dt>
                                <dd>
                                    <span
                                        className={`rounded-full px-2 py-1 text-[9px] font-semibold ${situationClass(state)}`}
                                    >
                                        {state}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div className="rounded-xl border p-4 text-xs">
                        <h3 className="mb-3 font-semibold">Contacto</h3>
                        <div className="space-y-3 text-slate-600">
                            <p className="flex items-center gap-2">
                                <Phone className="size-4" />{' '}
                                {supplier.phone || 'No registrado'}
                            </p>
                            <p className="flex items-center gap-2 break-all">
                                <Mail className="size-4 shrink-0" />{' '}
                                {supplier.email || 'No registrado'}
                            </p>
                            <p className="flex items-center gap-2">
                                <Building2 className="size-4" /> Dirección no
                                configurada
                            </p>
                        </div>
                    </div>
                </section>
                <section className="grid grid-cols-3 gap-2">
                    {[
                        ['Saldo por pagar', money(supplier.outstanding_cents)],
                        ['Saldo vencido', money(supplier.overdue_cents)],
                        ['Próximo vencimiento', supplier.next_due_on || '—'],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-xl border p-3">
                            <p className="text-[9px] text-slate-400">{label}</p>
                            <p className="mt-1 truncate text-xs font-semibold">
                                {value}
                            </p>
                            {label === 'Próximo vencimiento' &&
                                daysUntilDue !== null && (
                                    <p className="mt-1 text-[9px] text-amber-600">
                                        {daysUntilDue >= 0
                                            ? `${daysUntilDue} días`
                                            : `${Math.abs(daysUntilDue)} días vencido`}
                                    </p>
                                )}
                        </div>
                    ))}
                </section>
                <section className="rounded-xl border p-4">
                    <h3 className="text-xs font-semibold">
                        Antigüedad del saldo
                    </h3>
                    <div className="mt-3 flex h-2 overflow-hidden rounded-full bg-slate-100">
                        {[
                            ['current', 'bg-emerald-500'],
                            ['days_1_30', 'bg-cyan-400'],
                            ['days_31_60', 'bg-amber-400'],
                            ['days_60_plus', 'bg-rose-500'],
                        ].map(([key, color]) => (
                            <span
                                key={key}
                                className={color}
                                style={{
                                    width: `${totalAging ? (supplier.aging[key as keyof Aging] / totalAging) * 100 : 0}%`,
                                }}
                            />
                        ))}
                    </div>
                    <div className="mt-3 grid grid-cols-4 gap-2 text-center">
                        {[
                            ['Por vencer', supplier.aging.current],
                            ['1–30 días', supplier.aging.days_1_30],
                            ['31–60 días', supplier.aging.days_31_60],
                            ['+60 días', supplier.aging.days_60_plus],
                        ].map(([label, value]) => (
                            <div key={String(label)}>
                                <p className="text-[8px] text-slate-400">
                                    {label}
                                </p>
                                <p className="mt-1 text-[10px] font-semibold">
                                    {money(Number(value))}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>
                <section className="rounded-xl border">
                    <div className="border-b px-4 py-3">
                        <h3 className="text-xs font-semibold">
                            Últimas compras
                        </h3>
                    </div>
                    <div className="divide-y">
                        {supplier.recent_documents.map((document) => (
                            <Link
                                key={document.id}
                                href={routes.purchaseShow([
                                    company.public_id,
                                    document.public_id,
                                ])}
                                className="grid grid-cols-[1fr_auto] gap-3 px-4 py-3 text-[10px] hover:bg-[#fbf7f7]"
                            >
                                <span>
                                    <b className="text-[#8f1f30]">
                                        {documentLabel(document)}
                                    </b>
                                    <br />
                                    <span className="text-slate-400">
                                        {document.document_date} · vence{' '}
                                        {document.due_on}
                                    </span>
                                </span>
                                <span className="text-right">
                                    <b>{money(document.total_cents)}</b>
                                    <br />
                                    <span className="text-slate-400">
                                        Saldo{' '}
                                        {money(
                                            Math.max(
                                                0,
                                                numeric(document.total_cents) -
                                                    numeric(
                                                        document.paid_cents,
                                                    ),
                                            ),
                                        )}
                                    </span>
                                </span>
                            </Link>
                        ))}
                        {!supplier.recent_documents.length && (
                            <p className="p-5 text-center text-xs text-slate-400">
                                Sin compras registradas.
                            </p>
                        )}
                    </div>
                </section>
                <section className="rounded-xl border p-4 text-xs">
                    <h3 className="font-semibold">Información bancaria</h3>
                    <p className="mt-2 text-slate-500">
                        Aún no se ha configurado una cuenta bancaria para este
                        proveedor.
                    </p>
                </section>
                <div className="grid gap-2 sm:grid-cols-3">
                    <Link
                        className={primary}
                        href={routes.purchases.url(company.public_id, {
                            query: { supplier: supplier.id },
                        })}
                    >
                        <Plus className="size-4" /> Nueva compra
                    </Link>
                    {supplier.first_unpaid_document_public_id ? (
                        <Link
                            className="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-700 px-3 py-2 text-xs font-semibold text-white"
                            href={routes.purchaseShow([
                                company.public_id,
                                supplier.first_unpaid_document_public_id,
                            ])}
                        >
                            <WalletCards className="size-4" /> Registrar pago
                        </Link>
                    ) : (
                        <button type="button" disabled className={secondary}>
                            <WalletCards className="size-4" /> Sin saldo
                        </button>
                    )}
                    <Link
                        className={secondary}
                        href={routes.purchases.url(company.public_id, {
                            query: { supplier: supplier.id },
                        })}
                    >
                        Ver compras
                    </Link>
                </div>
            </div>
        </aside>
    );
}
