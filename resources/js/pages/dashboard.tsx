import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    Check,
    CheckCircle2,
    CircleDollarSign,
    Clock3,
    CreditCard,
    FileText,
    Mail,
    MapPin,
    Settings2,
    ShieldCheck,
    Sparkles,
    TrendingDown,
    TrendingUp,
    Users,
    WalletCards,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { dashboard } from '@/routes';
import {
    index as accounting,
    companyDashboard as accountingDashboard,
    sales as accountingSales,
} from '@/routes/accounting';
import { setup, invite, revoke } from '@/routes/workspace';

type Office = {
    name: string;
    email?: string | null;
    phone?: string | null;
    address_line_1?: string | null;
    city: string | null;
    status?: string;
};
type Props = {
    workspace: {
        name: string;
        phone: string | null;
        canManage: boolean;
        needsSetup: boolean;
        office: Office | null;
        offices: Office[];
        members: { name: string; email: string; role: string }[];
        invitations: { public_id: string; email: string; expires_at: string }[];
        companies: {
            public_id: string;
            name: string;
            tax_id: string;
            office_name: string;
        }[];
    };
    catalog: {
        code: string;
        name: string;
        amount: string;
        currency: string;
        period: string;
        limits: Record<string, number | null>;
        features: { code: string; name: string }[];
    } | null;
    metrics: {
        sales_cents: number;
        collections_cents: number;
        purchases_cents: number;
        payments_cents: number;
        receivable_cents: number;
        payable_cents: number;
        overdue_receivable_cents: number;
        overdue_payable_cents: number;
        income_cents: number;
        expense_cents: number;
        profit_cents: number;
        drafts: number;
        trend: {
            label: string;
            sales_cents: number;
            purchases_cents: number;
        }[];
        recent: {
            kind: string;
            title: string;
            detail: string;
            date: string;
            company_id: string;
        }[];
    };
    notice: string | null;
};
const primary =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#640000] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#420001] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#640000] disabled:cursor-wait disabled:opacity-60';
const secondary =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-[#e6dada] bg-white px-4 py-2 text-sm font-semibold text-[#640000] hover:bg-[#faf4f4] disabled:opacity-50';
const panel =
    'rounded-2xl border border-[#e9e2e2] bg-white shadow-[0_4px_24px_-16px_#42000140]';
const number = (value: number | null | undefined) =>
    value === null
        ? 'Sin límite'
        : value === undefined
          ? 'No definido'
          : new Intl.NumberFormat('es-GT').format(value);
const money = (value: number) =>
    new Intl.NumberFormat('es-GT', {
        style: 'currency',
        currency: 'GTQ',
        maximumFractionDigits: 0,
    }).format(value / 100);

export default function Dashboard({
    workspace,
    catalog,
    metrics,
    notice,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [step, setStep] = useState(0);
    const form = useForm({
        name: workspace.name,
        phone: workspace.phone ?? '',
        office_name: workspace.office?.name ?? 'Oficina principal',
        email: workspace.office?.email ?? '',
        address: workspace.office?.address_line_1 ?? '',
        city: workspace.office?.city ?? '',
    });
    const invitation = useForm({ email: '' });
    const cancellation = useForm({});
    const showSetup = workspace.canManage && (workspace.needsSetup || editing);
    const seats = workspace.members.length + workspace.invitations.length;
    const userLimit = catalog?.limits.users;
    const canInvite =
        userLimit === null || (userLimit !== undefined && seats < userLimit);
    const price = catalog
        ? new Intl.NumberFormat('es-GT', {
              style: 'currency',
              currency: catalog.currency,
          }).format(Number(catalog.amount))
        : '';

    function submitSetup(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (step < 2) {
            setStep(step + 1);

            return;
        }

        form.post(setup().url, {
            preserveScroll: true,
            onSuccess: () => {
                setEditing(false);
                setStep(0);
            },
            onError: (errors) => setStep(errors.name || errors.phone ? 0 : 1),
        });
    }

    function field(
        key: keyof typeof form.data,
        label: string,
        placeholder: string,
        type = 'text',
        required = true,
    ) {
        return (
            <div className="space-y-2">
                <label
                    className="block text-sm font-semibold text-slate-800"
                    htmlFor={key}
                >
                    {label}
                </label>
                <input
                    id={key}
                    type={type}
                    required={required}
                    maxLength={
                        key === 'phone'
                            ? 30
                            : key === 'city'
                              ? 100
                              : key === 'name' || key === 'office_name'
                                ? 150
                                : 255
                    }
                    value={form.data[key]}
                    onChange={(e) => form.setData(key, e.target.value)}
                    placeholder={placeholder}
                    aria-invalid={!!form.errors[key]}
                    aria-describedby={
                        form.errors[key] ? `${key}-error` : undefined
                    }
                    className="h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-base text-slate-900 outline-none placeholder:text-slate-400 focus:border-[#640000] focus:ring-2 focus:ring-[#640000]/10"
                />
                {form.errors[key] && (
                    <p
                        id={`${key}-error`}
                        role="alert"
                        className="text-sm text-red-700"
                    >
                        {form.errors[key]}
                    </p>
                )}
            </div>
        );
    }

    return (
        <>
            <Head
                title={showSetup ? 'Prepara tu oficina' : 'Mi organización'}
            />
            <div className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6 lg:p-8">
                <div className="mx-auto max-w-7xl space-y-6">
                    {notice && (
                        <div
                            role="status"
                            className="flex items-center gap-3 rounded-xl border border-[#c9dfd3] bg-[#eef8f2] p-4 text-sm text-[#235c3b]"
                        >
                            <CheckCircle2 className="size-5 shrink-0" />
                            {notice}
                        </div>
                    )}
                    <header className="relative overflow-hidden rounded-2xl bg-[#640000] p-6 text-white sm:p-8">
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute -top-28 -right-20 size-80 rounded-full border border-white/10"
                        />
                        <div className="relative flex flex-wrap items-center justify-between gap-6">
                            <div className="max-w-2xl">
                                <p className="mb-3 text-xs font-semibold tracking-[0.25em] text-[#e7bbbb]">
                                    ATLANTIA FINANCE / PANEL EJECUTIVO
                                </p>
                                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                                    {showSetup
                                        ? 'El siguiente capítulo empieza aquí.'
                                        : `Control total de ${workspace.name}`}
                                </h1>
                                <p className="mt-3 max-w-xl text-sm leading-6 text-[#edcece]">
                                    {showSetup
                                        ? 'Tu suscripción está activa. Personaliza tu organización y prepara tu primera oficina en unos pasos.'
                                        : 'Una sola vista para supervisar empresas, ventas, compras, cartera, resultados y operación contable.'}
                                </p>
                            </div>
                            <span className="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm">
                                <ShieldCheck className="size-4" />
                                Suscripción activa
                            </span>
                        </div>
                    </header>

                    {showSetup ? (
                        <div className="grid items-start gap-6 lg:grid-cols-[1fr_310px]">
                            <section
                                className={`${panel} p-5 sm:p-8`}
                                aria-label="Configuración inicial"
                            >
                                <ol className="mb-8 grid grid-cols-3 gap-2">
                                    {[
                                        'Organización',
                                        'Primera oficina',
                                        'Confirmación',
                                    ].map((label, index) => (
                                        <li
                                            key={label}
                                            aria-current={
                                                step === index
                                                    ? 'step'
                                                    : undefined
                                            }
                                            className={`flex flex-col gap-2 border-t-2 pt-3 text-xs sm:flex-row sm:items-center sm:text-sm ${step >= index ? 'border-[#640000] text-[#640000]' : 'border-slate-200 text-slate-500'}`}
                                        >
                                            <span
                                                className={`grid size-7 shrink-0 place-items-center rounded-full ${step >= index ? 'bg-[#640000] text-white' : 'bg-slate-100'}`}
                                            >
                                                {step > index ? (
                                                    <Check className="size-4" />
                                                ) : (
                                                    index + 1
                                                )}
                                            </span>
                                            {label}
                                        </li>
                                    ))}
                                </ol>
                                <form onSubmit={submitSetup}>
                                    <div className="mb-6">
                                        <h2 className="text-2xl font-semibold">
                                            {
                                                [
                                                    'Dale identidad a tu organización',
                                                    'Un lugar para empezar',
                                                    'Todo listo para comenzar',
                                                ][step]
                                            }
                                        </h2>
                                        <p className="mt-2 text-sm leading-6 text-slate-500">
                                            {
                                                [
                                                    'Así reconocerá tu equipo su espacio de trabajo.',
                                                    'Esta será la sede principal. Podrás invitar a tu equipo al finalizar.',
                                                    'Revisa tus datos. Guardar esta configuración no realiza ningún cobro.',
                                                ][step]
                                            }
                                        </p>
                                    </div>
                                    {step === 0 && (
                                        <div className="grid gap-5">
                                            {field(
                                                'name',
                                                'Nombre de la organización',
                                                'Ej. Oficina Contable del Caribe',
                                            )}
                                            {field(
                                                'phone',
                                                'Teléfono de contacto (opcional)',
                                                '+502 5555 5555',
                                                'tel',
                                                false,
                                            )}
                                            <div className="rounded-xl bg-[#faf4f4] p-4 text-sm leading-6 text-[#704848]">
                                                País: Guatemala · Moneda: GTQ ·
                                                Zona horaria: América/Guatemala
                                            </div>
                                        </div>
                                    )}
                                    {step === 1 && (
                                        <div className="grid gap-5 sm:grid-cols-2">
                                            {field(
                                                'office_name',
                                                'Nombre de la oficina',
                                                'Oficina principal',
                                            )}
                                            {field(
                                                'email',
                                                'Correo de contacto',
                                                'oficina@ejemplo.com',
                                                'email',
                                            )}
                                            <div className="sm:col-span-2">
                                                {field(
                                                    'address',
                                                    'Dirección',
                                                    'Calle, avenida y número',
                                                )}
                                            </div>
                                            {field(
                                                'city',
                                                'Municipio o ciudad',
                                                'Puerto Barrios',
                                            )}
                                        </div>
                                    )}
                                    {step === 2 && (
                                        <div className="space-y-4">
                                            <div className="rounded-xl border border-[#eadede] bg-[#fdfafa] p-5">
                                                <Building2 className="mb-3 size-6 text-[#640000]" />
                                                <h3 className="text-lg font-semibold">
                                                    {form.data.name}
                                                </h3>
                                                <p className="mt-3 text-sm text-slate-600">
                                                    {form.data.office_name}
                                                </p>
                                                <p className="mt-1 text-sm text-slate-600">
                                                    {form.data.address},{' '}
                                                    {form.data.city}
                                                </p>
                                                <p className="mt-1 text-sm text-slate-600">
                                                    {form.data.email}
                                                </p>
                                            </div>
                                            <p className="text-sm leading-6 text-slate-500">
                                                El propietario ocupa un cupo del
                                                plan. Las invitaciones al equipo
                                                son opcionales y se gestionan
                                                desde tu panel.
                                            </p>
                                        </div>
                                    )}
                                    <div className="mt-8 flex flex-wrap justify-between gap-3 border-t border-[#eee5e5] pt-5">
                                        <div>
                                            {step > 0 ? (
                                                <button
                                                    type="button"
                                                    className={secondary}
                                                    onClick={() =>
                                                        setStep(step - 1)
                                                    }
                                                    disabled={form.processing}
                                                >
                                                    <ArrowLeft className="size-4" />
                                                    Atrás
                                                </button>
                                            ) : editing ? (
                                                <button
                                                    type="button"
                                                    className={secondary}
                                                    onClick={() =>
                                                        setEditing(false)
                                                    }
                                                >
                                                    Cancelar
                                                </button>
                                            ) : (
                                                <span className="text-xs text-slate-500">
                                                    Paso {step + 1} de 3
                                                </span>
                                            )}
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={form.processing}
                                            className={primary}
                                        >
                                            {form.processing
                                                ? 'Guardando…'
                                                : step === 2
                                                  ? 'Guardar y abrir mi panel'
                                                  : 'Continuar'}
                                            <ArrowRight className="size-4" />
                                        </button>
                                    </div>
                                </form>
                            </section>
                            <aside className={`${panel} p-6`}>
                                <CreditCard className="mb-4 size-6 text-[#640000]" />
                                <p className="text-xs tracking-widest text-slate-500">
                                    TU PLAN
                                </p>
                                <h2 className="mt-2 text-2xl font-semibold">
                                    {catalog?.name ?? 'Suscripción activa'}
                                </h2>
                                <p className="mt-2 text-lg font-semibold text-[#640000]">
                                    {price}
                                    <span className="text-sm font-normal text-slate-500">
                                        {catalog
                                            ? catalog.period === 'annual'
                                                ? ' / año'
                                                : ' / mes'
                                            : ''}
                                    </span>
                                </p>
                                <ul className="mt-6 space-y-4 text-sm text-slate-600">
                                    <li className="flex gap-3">
                                        <Building2 className="size-4" />
                                        {number(catalog?.limits.offices)}{' '}
                                        oficinas
                                    </li>
                                    <li className="flex gap-3">
                                        <Users className="size-4" />
                                        {number(userLimit)} usuarios, incluido
                                        el propietario
                                    </li>
                                    <li className="flex gap-3">
                                        <Sparkles className="size-4" />
                                        {number(
                                            catalog?.limits.ai_queries,
                                        )}{' '}
                                        consultas de IA / mes
                                    </li>
                                </ul>
                                <p className="mt-6 border-t border-[#eee5e5] pt-4 text-xs leading-5 text-slate-500">
                                    Los límites se consultan desde tu
                                    suscripción y se comprueban en el servidor.
                                </p>
                            </aside>
                        </div>
                    ) : (
                        <>
                            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                {[
                                    {
                                        label: 'Ventas del mes',
                                        value: money(metrics.sales_cents),
                                        detail: `${money(metrics.collections_cents)} cobrados`,
                                        icon: TrendingUp,
                                        tone: 'bg-emerald-50 text-emerald-700',
                                    },
                                    {
                                        label: 'Resultado contable',
                                        value: money(metrics.profit_cents),
                                        detail: `${money(metrics.income_cents)} ingresos · ${money(metrics.expense_cents)} gastos`,
                                        icon:
                                            metrics.profit_cents >= 0
                                                ? TrendingUp
                                                : TrendingDown,
                                        tone:
                                            metrics.profit_cents >= 0
                                                ? 'bg-sky-50 text-sky-700'
                                                : 'bg-amber-50 text-amber-700',
                                    },
                                    {
                                        label: 'Cuentas por cobrar',
                                        value: money(metrics.receivable_cents),
                                        detail: `${money(metrics.overdue_receivable_cents)} vencidos`,
                                        icon: CircleDollarSign,
                                        tone: 'bg-[#f4e5e5] text-[#640000]',
                                    },
                                    {
                                        label: 'Cuentas por pagar',
                                        value: money(metrics.payable_cents),
                                        detail: `${money(metrics.overdue_payable_cents)} vencidos`,
                                        icon: WalletCards,
                                        tone: 'bg-amber-50 text-amber-700',
                                    },
                                ].map((card) => (
                                    <section
                                        key={card.label}
                                        className={`${panel} p-5`}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-medium text-slate-500">
                                                    {card.label}
                                                </p>
                                                <p className="mt-3 text-2xl font-semibold tracking-tight">
                                                    {card.value}
                                                </p>
                                            </div>
                                            <span
                                                className={`grid size-10 shrink-0 place-items-center rounded-xl ${card.tone}`}
                                            >
                                                <card.icon className="size-5" />
                                            </span>
                                        </div>
                                        <p className="mt-3 text-xs leading-5 text-slate-500">
                                            {card.detail}
                                        </p>
                                    </section>
                                ))}
                            </section>

                            <section className={`${panel} p-5 sm:p-6`}>
                                <div className="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-center">
                                    <div>
                                        <p className="text-xs font-semibold tracking-widest text-[#965f5f]">
                                            SITUACIÓN OPERATIVA
                                        </p>
                                        <h2 className="mt-2 text-xl font-semibold">
                                            La oficina en una sola lectura
                                        </h2>
                                        <p className="mt-2 text-sm leading-6 text-slate-500">
                                            {workspace.companies.length}{' '}
                                            empresas autorizadas ·{' '}
                                            {money(metrics.purchases_cents)} en
                                            compras del mes ·{' '}
                                            {money(metrics.payments_cents)} en
                                            pagos realizados.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-3">
                                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                                            <p className="flex items-center gap-2 text-sm font-semibold text-amber-900">
                                                <Clock3 className="size-4" />
                                                {metrics.drafts} pendientes
                                            </p>
                                            <p className="mt-1 text-xs text-amber-800/75">
                                                Documentos sin contabilizar
                                            </p>
                                        </div>
                                        <Link
                                            href={accounting()}
                                            className={primary}
                                        >
                                            Abrir centro de empresas
                                            <ArrowRight size={16} />
                                        </Link>
                                    </div>
                                </div>
                            </section>

                            <section className={`${panel} p-5 sm:p-6`}>
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-xs font-semibold tracking-widest text-[#965f5f]">
                                            OPERACIÓN DE LA OFICINA
                                        </p>
                                        <h2 className="mt-2 text-xl font-semibold">
                                            Clientes, comprobantes y cobros
                                        </h2>
                                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                                            Cada empresa contable tiene su
                                            propia cartera de clientes. Entra
                                            directamente a clientes,
                                            comprobantes internos, pagos y
                                            cuentas vencidas.
                                        </p>
                                    </div>
                                    <Link
                                        href={accounting()}
                                        className={secondary}
                                    >
                                        Ver todas las empresas
                                        <ArrowRight size={16} />
                                    </Link>
                                </div>
                                {workspace.companies.length ? (
                                    <div className="mt-5 grid gap-4 lg:grid-cols-2">
                                        {workspace.companies.map((company) => (
                                            <article
                                                key={company.public_id}
                                                className="rounded-2xl border border-[#eee5e5] bg-[#fdfbfb] p-5"
                                            >
                                                <div className="flex items-start gap-3">
                                                    <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-[#f1e4e4] text-[#640000]">
                                                        <FileText size={21} />
                                                    </span>
                                                    <div className="min-w-0">
                                                        <h3 className="font-semibold">
                                                            {company.name}
                                                        </h3>
                                                        <p className="mt-1 text-xs text-slate-500">
                                                            NIT {company.tax_id}{' '}
                                                            ·{' '}
                                                            {
                                                                company.office_name
                                                            }
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="mt-4 flex flex-wrap gap-2">
                                                    <Link
                                                        href={accountingDashboard(
                                                            company.public_id,
                                                        )}
                                                        className={primary}
                                                    >
                                                        Abrir panel financiero
                                                        <ArrowRight size={16} />
                                                    </Link>
                                                    <Link
                                                        href={accountingSales(
                                                            company.public_id,
                                                        )}
                                                        className={secondary}
                                                    >
                                                        Ventas y cobros
                                                    </Link>
                                                </div>
                                            </article>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="mt-5 rounded-2xl border border-dashed border-[#d9caca] p-5 text-sm text-slate-600">
                                        Primero registra una empresa cliente en
                                        “Empresas y contabilidad”. Después
                                        aparecerá aquí el acceso directo a su
                                        cartera y comprobantes.
                                    </div>
                                )}
                                <p className="mt-4 text-xs text-slate-500">
                                    Los comprobantes generados son internos y no
                                    sustituyen una factura FEL autorizada por la
                                    SAT.
                                </p>
                            </section>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                {[
                                    {
                                        label: 'Oficinas',
                                        value: `${workspace.offices.filter((o) => o.status === 'active').length}`,
                                        detail: workspace.canManage
                                            ? `De ${number(catalog?.limits.offices)} incluidas en el plan`
                                            : 'Sedes asignadas a tu cuenta',
                                        icon: Building2,
                                    },
                                    ...(workspace.canManage
                                        ? [
                                              {
                                                  label: 'Cupos de equipo',
                                                  value: `${seats}`,
                                                  detail: `${workspace.members.length} activos · ${workspace.invitations.length} pendientes`,
                                                  icon: Users,
                                              },
                                              {
                                                  label: 'Almacenamiento incluido',
                                                  value:
                                                      catalog?.limits
                                                          .storage_bytes ===
                                                      null
                                                          ? 'Sin límite'
                                                          : catalog?.limits
                                                                  .storage_bytes !==
                                                              undefined
                                                            ? `${Number((catalog.limits.storage_bytes / 1073741824).toFixed(1))} GB`
                                                            : '—',
                                                  detail: 'Capacidad contratada, no consumo medido',
                                                  icon: ShieldCheck,
                                              },
                                              {
                                                  label: 'Consultas de IA',
                                                  value: number(
                                                      catalog?.limits
                                                          .ai_queries,
                                                  ),
                                                  detail: 'Cuota mensual del plan',
                                                  icon: Sparkles,
                                              },
                                          ]
                                        : []),
                                ].map((card) => (
                                    <section
                                        key={card.label}
                                        className={`${panel} p-5`}
                                    >
                                        <div className="flex items-center justify-between text-sm text-slate-500">
                                            {card.label}
                                            <card.icon className="size-5 text-[#a66b6b]" />
                                        </div>
                                        <p className="mt-4 text-3xl font-semibold tracking-tight">
                                            {card.value}
                                        </p>
                                        <p className="mt-2 text-xs leading-5 text-slate-500">
                                            {card.detail}
                                        </p>
                                    </section>
                                ))}
                            </div>
                            <div className="grid items-start gap-6 xl:grid-cols-[1fr_340px]">
                                <div className="space-y-6">
                                    <section className={`${panel} p-6`}>
                                        <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                                            <h2 className="text-lg font-semibold">
                                                Tus oficinas
                                            </h2>
                                            {workspace.canManage && (
                                                <button
                                                    type="button"
                                                    className={secondary}
                                                    onClick={() => {
                                                        setEditing(true);
                                                        setStep(0);
                                                    }}
                                                >
                                                    <Settings2 className="size-4" />
                                                    Editar oficina principal
                                                </button>
                                            )}
                                        </div>
                                        {workspace.offices.map(
                                            (office, index) => (
                                                <div
                                                    key={index}
                                                    className="flex items-center gap-4 rounded-xl border border-[#eee5e5] p-4"
                                                >
                                                    <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-[#f5e8e8] text-[#640000]">
                                                        <Building2 className="size-6" />
                                                    </span>
                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-semibold">
                                                            {office.name}
                                                        </h3>
                                                        <p className="mt-1 flex items-center gap-1 text-sm text-slate-500">
                                                            <MapPin className="size-3" />
                                                            {office.city ||
                                                                'Ubicación por completar'}
                                                        </p>
                                                    </div>
                                                    <span className="rounded-full bg-[#eef8f2] px-3 py-1 text-xs text-[#235c3b]">
                                                        {office.status ===
                                                        'active'
                                                            ? 'Activa'
                                                            : 'Pendiente'}
                                                    </span>
                                                </div>
                                            ),
                                        )}
                                        {!workspace.offices.length && (
                                            <p className="text-sm text-slate-500">
                                                El propietario aún debe
                                                asignarte una oficina.
                                            </p>
                                        )}
                                    </section>
                                    {workspace.canManage && (
                                        <section className={`${panel} p-6`}>
                                            <h2 className="text-lg font-semibold">
                                                Un buen equipo empieza con una
                                                invitación
                                            </h2>
                                            <p className="mt-2 text-sm leading-6 text-slate-500">
                                                Invita colaboradores a tu
                                                oficina principal. Cada
                                                invitación vigente reserva un
                                                cupo durante 72 horas.
                                            </p>
                                            <form
                                                className="mt-5"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    invitation.post(
                                                        invite().url,
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () =>
                                                                invitation.reset(),
                                                        },
                                                    );
                                                }}
                                            >
                                                <label
                                                    htmlFor="invite-email"
                                                    className="mb-2 block text-sm font-semibold"
                                                >
                                                    Correo del colaborador
                                                </label>
                                                <div className="flex flex-col gap-3 sm:flex-row">
                                                    <input
                                                        id="invite-email"
                                                        type="email"
                                                        required
                                                        maxLength={255}
                                                        value={
                                                            invitation.data
                                                                .email
                                                        }
                                                        onChange={(e) =>
                                                            invitation.setData(
                                                                'email',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="colaborador@oficina.com"
                                                        className="h-12 min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-4 text-slate-900 focus:outline-[#640000]"
                                                    />
                                                    <button
                                                        className={primary}
                                                        disabled={
                                                            invitation.processing ||
                                                            !canInvite
                                                        }
                                                    >
                                                        <Mail className="size-4" />
                                                        {invitation.processing
                                                            ? 'Enviando…'
                                                            : 'Enviar invitación'}
                                                    </button>
                                                </div>
                                                {!canInvite && (
                                                    <p className="mt-2 text-sm text-[#864d20]">
                                                        {workspace.invitations
                                                            .length
                                                            ? 'No hay cupos disponibles. Puedes revocar una invitación pendiente para liberar su cupo.'
                                                            : 'Todos los cupos del plan están ocupados por colaboradores activos.'}
                                                    </p>
                                                )}
                                                {invitation.errors.email && (
                                                    <p
                                                        role="alert"
                                                        className="mt-2 text-sm text-red-700"
                                                    >
                                                        {
                                                            invitation.errors
                                                                .email
                                                        }
                                                    </p>
                                                )}
                                            </form>
                                            <div className="mt-6 divide-y divide-[#eee5e5]">
                                                {workspace.members.map(
                                                    (member) => (
                                                        <div
                                                            key={member.email}
                                                            className="flex flex-wrap items-center justify-between gap-3 py-4"
                                                        >
                                                            <div>
                                                                <p className="text-sm font-semibold">
                                                                    {
                                                                        member.name
                                                                    }
                                                                </p>
                                                                <p className="text-xs break-all text-slate-500">
                                                                    {
                                                                        member.email
                                                                    }
                                                                </p>
                                                            </div>
                                                            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">
                                                                {member.role ===
                                                                'owner'
                                                                    ? 'Propietario'
                                                                    : 'Colaborador'}
                                                            </span>
                                                        </div>
                                                    ),
                                                )}
                                                {workspace.invitations.map(
                                                    (item) => (
                                                        <div
                                                            key={item.public_id}
                                                            className="flex flex-wrap items-center justify-between gap-3 py-4"
                                                        >
                                                            <div>
                                                                <p className="text-sm font-semibold break-all">
                                                                    {item.email}
                                                                </p>
                                                                <p className="text-xs text-slate-500">
                                                                    Pendiente ·
                                                                    vence{' '}
                                                                    {new Date(
                                                                        item.expires_at,
                                                                    ).toLocaleDateString(
                                                                        'es-GT',
                                                                    )}
                                                                </p>
                                                            </div>
                                                            <button
                                                                className="min-h-10 text-sm font-semibold text-[#640000] underline"
                                                                disabled={
                                                                    cancellation.processing
                                                                }
                                                                onClick={() => {
                                                                    if (
                                                                        window.confirm(
                                                                            '¿Revocar esta invitación y liberar su cupo?',
                                                                        )
                                                                    ) {
                                                                        cancellation.delete(
                                                                            revoke(
                                                                                item.public_id,
                                                                            )
                                                                                .url,
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        );
                                                                    }
                                                                }}
                                                            >
                                                                Revocar
                                                            </button>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </section>
                                    )}
                                </div>
                                <aside className="space-y-5">
                                    {catalog && (
                                        <section className={`${panel} p-6`}>
                                            <p className="text-xs font-semibold tracking-widest text-[#965f5f]">
                                                SUSCRIPCIÓN
                                            </p>
                                            <h2 className="mt-3 text-2xl font-semibold">
                                                {catalog.name}
                                            </h2>
                                            <p className="mt-2 text-2xl font-semibold text-[#640000]">
                                                {price}
                                                <span className="text-sm font-normal text-slate-500">
                                                    {' '}
                                                    /{' '}
                                                    {catalog.period === 'annual'
                                                        ? 'año'
                                                        : 'mes'}
                                                </span>
                                            </p>
                                            <div className="mt-5 border-t border-[#eee5e5] pt-4 text-sm text-slate-600">
                                                <p>
                                                    {number(
                                                        catalog.limits.users,
                                                    )}{' '}
                                                    usuarios incluidos
                                                </p>
                                                <p className="mt-2">
                                                    {number(
                                                        catalog.limits
                                                            .active_cases,
                                                    )}{' '}
                                                    empresas cliente incluidas
                                                </p>
                                            </div>
                                        </section>
                                    )}
                                    <section className="rounded-2xl bg-[#f1e4e4] p-6">
                                        <Sparkles className="size-6 text-[#640000]" />
                                        <h2 className="mt-4 font-semibold text-[#640000]">
                                            Suite contable habilitada
                                        </h2>
                                        <p className="mt-3 text-sm leading-6 text-[#755050]">
                                            Gestiona ventas, compras, cobros,
                                            pagos, catálogo, períodos, asientos,
                                            documentos y estados financieros con
                                            el aislamiento definido por tu plan.
                                        </p>
                                        <Link
                                            href={accounting()}
                                            className="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-[#640000] underline"
                                        >
                                            Abrir centro contable{' '}
                                            <ArrowRight size={16} />
                                        </Link>
                                    </section>
                                </aside>
                            </div>
                        </>
                    )}
                    <footer className="pb-2 text-center text-xs text-slate-500">
                        Atlantia Finance · Una solución de Atlantia Systems
                    </footer>
                </div>
            </div>
        </>
    );
}
Dashboard.layout = {
    breadcrumbs: [{ title: 'Mi organización', href: dashboard() }],
};
