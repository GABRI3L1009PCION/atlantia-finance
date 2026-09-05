import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ChevronDown,
    CircleDollarSign,
    Clock3,
    Download,
    EllipsisVertical,
    FileText,
    Mail,
    Pencil,
    Phone,
    Plus,
    Search,
    Upload,
    UserRound,
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

type Document = {
    id: number;
    public_id: string;
    number: number | null;
    status: string;
    issued_on: string;
    due_on: string;
    total_cents: number | string;
    paid_cents: number | string;
};

type Customer = {
    id: number;
    name: string;
    tax_id: string | null;
    email: string | null;
    created_at: string;
    updated_at: string;
    document_count: number | string;
    invoiced_cents: number | string;
    collected_cents: number | string;
    outstanding_cents: number | string;
    overdue_cents: number | string;
    last_sale_on: string | null;
    recent_documents: Document[];
};

type Summary = {
    active: number;
    pending: number;
    outstanding: number;
    overdue: number;
    over_limit: number;
};

const number = (value: number | string) => Number(value);

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function situation(customer: Customer): 'Al día' | 'Pendiente' | 'Vencido' {
    if (number(customer.overdue_cents) > 0) {
        return 'Vencido';
    }

    if (number(customer.outstanding_cents) > 0) {
        return 'Pendiente';
    }

    return 'Al día';
}

function situationClass(state: ReturnType<typeof situation>): string {
    if (state === 'Vencido') {
        return 'bg-rose-50 text-rose-700';
    }

    if (state === 'Pendiente') {
        return 'bg-amber-50 text-amber-700';
    }

    return 'bg-emerald-50 text-emerald-700';
}

function documentLabel(document: Document): string {
    return document.number
        ? `INT-${String(document.number).padStart(4, '0')}`
        : 'BORRADOR';
}

function documentState(document: Document, today: string): string {
    if (document.status === 'void') {
        return 'Anulado';
    }

    if (document.status === 'draft') {
        return 'Borrador';
    }

    if (number(document.paid_cents) >= number(document.total_cents)) {
        return 'Pagado';
    }

    if (document.due_on < today) {
        return 'Vencido';
    }

    return number(document.paid_cents) > 0 ? 'Parcial' : 'Por cobrar';
}

export default function Customers({
    company,
    customers,
    filters,
    summary,
    canWrite,
    today,
}: {
    company: Company;
    customers: Page<Customer>;
    filters: { search: string; situation: string };
    summary: Summary;
    canWrite: boolean;
    today: string;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(
        customers.data[0]?.id ?? null,
    );
    const [showCreate, setShowCreate] = useState(false);
    const search = useForm({
        search: filters.search ?? '',
        situation: filters.situation ?? 'all',
    });
    const create = useForm({ name: '', tax_id: '', email: '' });

    const selected = useMemo(
        () =>
            customers.data.find((customer) => customer.id === selectedId) ??
            customers.data[0] ??
            null,
        [customers.data, selectedId],
    );

    const applyFilters = (event?: FormEvent) => {
        event?.preventDefault();
        router.get(
            routes.salesCustomers.url(company.public_id),
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

    const overdueShare =
        summary.outstanding > 0
            ? Math.round((summary.overdue / summary.outstanding) * 1000) / 10
            : 0;

    return (
        <div className="min-h-full bg-[#f7f8fb] p-3 text-[#142135] sm:p-5">
            <Head title="Clientes" />
            <div className="mx-auto max-w-[1680px] space-y-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Clientes
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Gestión comercial, crédito y cuentas por cobrar
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canWrite && (
                            <button
                                type="button"
                                className={primary}
                                onClick={() => setShowCreate(true)}
                            >
                                <Plus className="size-4" /> Nuevo cliente
                            </button>
                        )}
                        <button
                            type="button"
                            className={secondary}
                            disabled
                            title="La importación masiva se habilitará en una próxima entrega"
                        >
                            <Upload className="size-4" /> Importar
                        </button>
                        <button
                            type="button"
                            className={secondary}
                            onClick={() => window.print()}
                        >
                            <Download className="size-4" /> Exportar
                            <ChevronDown className="size-4" />
                        </button>
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {[
                        {
                            label: 'Clientes activos',
                            value: summary.active,
                            hint: 'Dentro de esta empresa',
                            icon: UsersRound,
                            tone: 'bg-blue-50 text-blue-700',
                        },
                        {
                            label: 'Con saldo pendiente',
                            value: summary.pending,
                            hint: `${summary.active ? Math.round((summary.pending / summary.active) * 1000) / 10 : 0}% del total`,
                            icon: WalletCards,
                            tone: 'bg-orange-50 text-orange-700',
                        },
                        {
                            label: 'Saldo total por cobrar',
                            value: money(summary.outstanding),
                            hint: 'Cartera emitida pendiente',
                            icon: CircleDollarSign,
                            tone: 'bg-emerald-50 text-emerald-700',
                        },
                        {
                            label: 'Cartera vencida',
                            value: money(summary.overdue),
                            hint: `${overdueShare}% del total por cobrar`,
                            icon: Clock3,
                            tone: 'bg-rose-50 text-rose-700',
                        },
                        {
                            label: 'Límite excedido',
                            value: summary.over_limit,
                            hint: 'Sin límites configurados',
                            icon: AlertTriangle,
                            tone: 'bg-amber-50 text-amber-700',
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
                                <strong className="mt-1 block truncate text-lg text-[#172033]">
                                    {metric.value}
                                </strong>
                                <p className="mt-1 text-[10px] text-slate-400">
                                    {metric.hint}
                                </p>
                            </div>
                        </article>
                    ))}
                </section>

                <div className="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_440px]">
                    <section className="min-w-0 overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_22px_rgba(31,38,55,.05)]">
                        <form
                            className="grid gap-3 border-b border-[#e8ebf1] p-4 lg:grid-cols-[minmax(220px,1fr)_180px_180px_130px]"
                            onSubmit={applyFilters}
                        >
                            <label className="relative">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={search.data.search}
                                    onChange={(event) =>
                                        search.setData(
                                            'search',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 w-full rounded-xl border border-[#dfe3ea] bg-white pr-3 pl-10 text-sm outline-none focus:border-[#8f1f30] focus:ring-2 focus:ring-[#8f1f30]/10"
                                    placeholder="Buscar cliente, NIT o correo..."
                                    aria-label="Buscar cliente"
                                />
                            </label>
                            <label>
                                <span className="sr-only">Estado</span>
                                <select
                                    className="h-10 w-full rounded-xl border border-[#dfe3ea] bg-white px-3 text-sm outline-none"
                                    aria-label="Estado del cliente"
                                    defaultValue="active"
                                >
                                    <option value="active">Activos</option>
                                </select>
                            </label>
                            <label>
                                <span className="sr-only">Situación</span>
                                <select
                                    value={search.data.situation}
                                    onChange={(event) =>
                                        search.setData(
                                            'situation',
                                            event.target.value,
                                        )
                                    }
                                    className="h-10 w-full rounded-xl border border-[#dfe3ea] bg-white px-3 text-sm outline-none"
                                    aria-label="Situación de cartera"
                                >
                                    <option value="all">Toda situación</option>
                                    <option value="current">Al día</option>
                                    <option value="pending">Pendiente</option>
                                    <option value="overdue">Vencido</option>
                                </select>
                            </label>
                            <button className={primary}>Filtrar</button>
                        </form>

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[880px] text-left text-xs">
                                <thead className="bg-[#fbfcfd] text-[10px] tracking-wide text-slate-500 uppercase">
                                    <tr>
                                        <th className="p-4">Cliente</th>
                                        <th className="p-4">NIT</th>
                                        <th className="p-4">Condición</th>
                                        <th className="p-4 text-right">
                                            Ventas acumuladas
                                        </th>
                                        <th className="p-4 text-right">
                                            Por cobrar
                                        </th>
                                        <th className="p-4 text-right">
                                            Vencido
                                        </th>
                                        <th className="p-4">Situación</th>
                                        <th className="p-4">Estado</th>
                                        <th className="p-4" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {customers.data.map((customer, index) => {
                                        const state = situation(customer);
                                        const selectedRow =
                                            selected?.id === customer.id;
                                        const colors = [
                                            'bg-[#8f1f30]',
                                            'bg-emerald-600',
                                            'bg-violet-600',
                                            'bg-orange-500',
                                            'bg-cyan-600',
                                            'bg-pink-500',
                                            'bg-blue-600',
                                            'bg-amber-500',
                                        ];

                                        return (
                                            <tr
                                                key={customer.id}
                                                className={`cursor-pointer border-t border-[#edf0f4] transition hover:bg-[#fbf7f7] ${selectedRow ? 'bg-[#fdf5f5]' : ''}`}
                                                onClick={() =>
                                                    setSelectedId(customer.id)
                                                }
                                            >
                                                <td className="p-4">
                                                    <div className="flex items-center gap-3">
                                                        <span
                                                            className={`grid size-8 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white ${colors[index % colors.length]}`}
                                                        >
                                                            {initials(
                                                                customer.name,
                                                            )}
                                                        </span>
                                                        <div className="min-w-0">
                                                            <p className="truncate font-semibold text-[#172033]">
                                                                {customer.name}
                                                            </p>
                                                            <p className="mt-0.5 truncate text-[10px] text-slate-400">
                                                                {customer.email ||
                                                                    'Correo no registrado'}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="p-4 text-slate-600">
                                                    {customer.tax_id || 'C/F'}
                                                </td>
                                                <td className="p-4 text-slate-600">
                                                    {number(
                                                        customer.outstanding_cents,
                                                    ) > 0
                                                        ? 'Crédito'
                                                        : 'Contado'}
                                                </td>
                                                <td className="p-4 text-right font-medium">
                                                    {money(
                                                        customer.invoiced_cents,
                                                    )}
                                                </td>
                                                <td className="p-4 text-right font-medium">
                                                    {money(
                                                        customer.outstanding_cents,
                                                    )}
                                                </td>
                                                <td
                                                    className={`p-4 text-right font-medium ${number(customer.overdue_cents) > 0 ? 'text-rose-700' : ''}`}
                                                >
                                                    {money(
                                                        customer.overdue_cents,
                                                    )}
                                                </td>
                                                <td className="p-4">
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${situationClass(state)}`}
                                                    >
                                                        {state}
                                                    </span>
                                                </td>
                                                <td className="p-4">
                                                    <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">
                                                        Activo
                                                    </span>
                                                </td>
                                                <td className="p-4">
                                                    <button
                                                        type="button"
                                                        aria-label={`Acciones de ${customer.name}`}
                                                        className="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-[#640000]"
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            setSelectedId(
                                                                customer.id,
                                                            );
                                                        }}
                                                    >
                                                        <EllipsisVertical className="size-4" />
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {!customers.data.length && (
                            <div className="grid min-h-56 place-items-center p-8 text-center">
                                <div>
                                    <UsersRound className="mx-auto size-10 text-slate-300" />
                                    <p className="mt-3 font-semibold">
                                        No se encontraron clientes
                                    </p>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Limpia los filtros o registra el primer
                                        cliente de la empresa.
                                    </p>
                                </div>
                            </div>
                        )}
                        <div className="px-4 pb-4">
                            <Pager page={customers} />
                        </div>
                    </section>

                    <CustomerPanel
                        company={company}
                        customer={selected}
                        today={today}
                    />
                </div>
            </div>

            {showCreate && (
                <div
                    className="fixed inset-0 z-[100] flex items-center justify-center bg-[#07101f]/70 p-4 backdrop-blur-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Nuevo cliente"
                    onMouseDown={(event) => {
                        if (event.currentTarget === event.target) {
                            setShowCreate(false);
                        }
                    }}
                >
                    <section className="w-full max-w-xl overflow-hidden rounded-3xl bg-white shadow-2xl">
                        <header className="flex items-start justify-between gap-5 border-b border-slate-200 p-6">
                            <div>
                                <p className="text-xs font-semibold tracking-[.18em] text-[#8f1f30] uppercase">
                                    Atlantia Finance
                                </p>
                                <h2 className="mt-1 text-2xl font-semibold">
                                    Nuevo cliente
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    Registra los datos comerciales básicos.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowCreate(false)}
                                className="grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500"
                                aria-label="Cerrar"
                            >
                                <X className="size-5" />
                            </button>
                        </header>
                        <form
                            className="space-y-4 p-6"
                            onSubmit={(event) => {
                                event.preventDefault();
                                create.post(
                                    routes.salesCustomer.url(company.public_id),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            create.reset();
                                            setShowCreate(false);
                                        },
                                    },
                                );
                            }}
                        >
                            <label className="block text-sm font-medium">
                                Nombre o razón social
                                <input
                                    required
                                    maxLength={150}
                                    className={field}
                                    value={create.data.name}
                                    onChange={(event) =>
                                        create.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <label className="block text-sm font-medium">
                                    NIT / identificador
                                    <input
                                        maxLength={30}
                                        className={field}
                                        value={create.data.tax_id}
                                        onChange={(event) =>
                                            create.setData(
                                                'tax_id',
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
                                        value={create.data.email}
                                        onChange={(event) =>
                                            create.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <Errors errors={create.errors} />
                            <div className="flex justify-end gap-3 pt-2">
                                <button
                                    type="button"
                                    className={secondary}
                                    onClick={() => setShowCreate(false)}
                                >
                                    Cancelar
                                </button>
                                <button
                                    className={primary}
                                    disabled={create.processing}
                                >
                                    Guardar cliente
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
            )}
        </div>
    );
}

function CustomerPanel({
    company,
    customer,
    today,
}: {
    company: Company;
    customer: Customer | null;
    today: string;
}) {
    if (!customer) {
        return (
            <aside className="grid min-h-80 place-items-center rounded-2xl border border-[#e4e8ef] bg-white p-8 text-center shadow-[0_7px_22px_rgba(31,38,55,.05)]">
                <div>
                    <UserRound className="mx-auto size-11 text-slate-300" />
                    <p className="mt-3 font-semibold">Selecciona un cliente</p>
                    <p className="mt-1 text-sm text-slate-500">
                        Su cartera y documentos aparecerán aquí.
                    </p>
                </div>
            </aside>
        );
    }

    const state = situation(customer);
    const collected = number(customer.collected_cents);
    const outstanding = number(customer.outstanding_cents);
    const invoiced = number(customer.invoiced_cents);
    const collectionRate = invoiced > 0 ? (collected / invoiced) * 100 : 0;

    return (
        <aside className="overflow-hidden rounded-2xl border border-[#e4e8ef] bg-white shadow-[0_7px_22px_rgba(31,38,55,.05)]">
            <header className="border-b border-[#e7eaf0] p-5">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-[#8f1f30] text-xs font-bold text-white">
                            {initials(customer.name)}
                        </span>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="truncate font-semibold">
                                    {customer.name}
                                </h2>
                                <span className="rounded-full bg-emerald-50 px-2 py-1 text-[9px] font-semibold text-emerald-700">
                                    Activo
                                </span>
                            </div>
                            <p className="mt-0.5 text-xs text-slate-500">
                                NIT {customer.tax_id || 'C/F'}
                            </p>
                        </div>
                    </div>
                    <span
                        className={`rounded-full px-2.5 py-1 text-[10px] font-semibold ${situationClass(state)}`}
                    >
                        {state}
                    </span>
                </div>
                <div className="mt-5 flex gap-5 border-b border-[#e7eaf0] text-[10px] font-semibold text-slate-500">
                    {[
                        'Resumen',
                        'Ventas',
                        'Cuentas por cobrar',
                        'Cobros',
                        'Documentos',
                    ].map((tab, index) => (
                        <span
                            key={tab}
                            className={`-mb-px pb-3 ${index === 0 ? 'border-b-2 border-[#8f1f30] text-[#8f1f30]' : ''}`}
                        >
                            {tab}
                        </span>
                    ))}
                </div>
            </header>

            <div className="space-y-4 p-4">
                <section className="grid gap-3 rounded-xl border border-[#e7eaf0] p-4 sm:grid-cols-3 2xl:grid-cols-3">
                    <div>
                        <p className="text-[10px] text-slate-400">
                            Contacto principal
                        </p>
                        <p className="mt-1 text-xs font-semibold">
                            {customer.name}
                        </p>
                        <p className="mt-1 text-[10px] text-slate-500">
                            Cliente comercial
                        </p>
                        <div className="mt-3 space-y-2 text-[10px] text-slate-500">
                            <p className="flex items-center gap-2">
                                <Phone className="size-3" /> No registrado
                            </p>
                            <p className="flex items-center gap-2 break-all">
                                <Mail className="size-3 shrink-0" />
                                {customer.email || 'No registrado'}
                            </p>
                        </div>
                    </div>
                    <div className="border-slate-100 sm:border-x sm:px-3">
                        <p className="text-[10px] text-slate-400">
                            Condición de pago
                        </p>
                        <p className="mt-1 text-sm font-semibold">
                            {outstanding > 0 ? 'Crédito' : 'Contado'}
                        </p>
                        <p className="mt-2 text-[10px] text-slate-500">
                            Según cartera vigente
                        </p>
                    </div>
                    <div>
                        <p className="text-[10px] text-slate-400">
                            Límite de crédito
                        </p>
                        <p className="mt-1 text-sm font-semibold">
                            No definido
                        </p>
                        <p className="mt-2 text-[10px] text-slate-500">
                            Saldo {money(outstanding)}
                        </p>
                    </div>
                </section>

                <section className="grid grid-cols-2 gap-2 rounded-xl border border-[#e7eaf0] p-3 sm:grid-cols-4 2xl:grid-cols-4">
                    {[
                        ['Ventas acumuladas', money(invoiced)],
                        ['Saldo por cobrar', money(outstanding)],
                        ['Saldo vencido', money(customer.overdue_cents)],
                        ['Última venta', customer.last_sale_on || '—'],
                    ].map(([label, value]) => (
                        <div key={label} className="min-w-0 p-2">
                            <p className="text-[9px] text-slate-400">{label}</p>
                            <p className="mt-1 truncate text-xs font-semibold">
                                {value}
                            </p>
                        </div>
                    ))}
                </section>

                <section className="rounded-xl border border-[#e7eaf0] p-4">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-xs font-semibold">
                            Recuperación de cartera
                        </h3>
                        <span className="text-[10px] font-semibold text-emerald-700">
                            {collectionRate.toFixed(1)}% cobrado
                        </span>
                    </div>
                    <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div
                            className="h-full rounded-full bg-gradient-to-r from-[#8f1f30] to-emerald-500"
                            style={{
                                width: `${Math.min(100, collectionRate)}%`,
                            }}
                        />
                    </div>
                    <div className="mt-3 flex justify-between text-[10px] text-slate-500">
                        <span>Cobrado {money(collected)}</span>
                        <span>Pendiente {money(outstanding)}</span>
                    </div>
                </section>

                <section className="rounded-xl border border-[#e7eaf0]">
                    <div className="flex items-center justify-between border-b border-[#e7eaf0] px-4 py-3">
                        <h3 className="text-xs font-semibold">
                            Últimos documentos
                        </h3>
                        <span className="text-[10px] text-slate-400">
                            {customer.document_count} emitidos
                        </span>
                    </div>
                    <div className="divide-y divide-[#edf0f4]">
                        {customer.recent_documents.map((document) => {
                            const stateLabel = documentState(document, today);

                            return (
                                <Link
                                    key={document.id}
                                    href={routes.invoice([
                                        company.public_id,
                                        document.public_id,
                                    ])}
                                    className="grid grid-cols-[auto_1fr_auto] items-center gap-3 px-4 py-3 text-[10px] transition hover:bg-[#fbf7f7]"
                                >
                                    <FileText className="size-4 text-slate-400" />
                                    <div className="min-w-0">
                                        <p className="font-semibold text-[#8f1f30]">
                                            {documentLabel(document)}
                                        </p>
                                        <p className="mt-0.5 text-slate-400">
                                            {document.issued_on} ·{' '}
                                            {money(document.total_cents)}
                                        </p>
                                    </div>
                                    <span className="rounded-full bg-slate-50 px-2 py-1 font-medium text-slate-600">
                                        {stateLabel}
                                    </span>
                                </Link>
                            );
                        })}
                        {!customer.recent_documents.length && (
                            <p className="px-4 py-6 text-center text-xs text-slate-400">
                                Este cliente aún no tiene documentos.
                            </p>
                        )}
                    </div>
                </section>

                <div className="grid gap-2 sm:grid-cols-3">
                    <Link
                        href={routes.sales(company.public_id)}
                        className={primary}
                    >
                        <Plus className="size-4" /> Nueva venta
                    </Link>
                    <Link
                        href={routes.salesCustomerShow([
                            company.public_id,
                            customer.id,
                        ])}
                        className={secondary}
                    >
                        <Check className="size-4" /> Estado de cuenta
                    </Link>
                    <Link
                        href={routes.salesCustomerShow([
                            company.public_id,
                            customer.id,
                        ])}
                        className={secondary}
                    >
                        <Pencil className="size-4" /> Editar
                    </Link>
                </div>
                <p className="text-[10px] leading-5 text-slate-400">
                    Creado: {customer.created_at} · Última actualización:{' '}
                    {customer.updated_at}
                </p>
            </div>
        </aside>
    );
}
