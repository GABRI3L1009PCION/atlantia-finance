import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    ArrowRight,
    BarChart3,
    Building2,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    Code2,
    CreditCard,
    FileText,
    Mail,
    Plug,
    Plus,
    Shield,
    Trash2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    Notice,
    PageHeader,
    Shell,
    input,
    panel,
    primaryButton,
    scoped,
    secondaryButton,
    useOfficeQuery,
} from '@/components/office-admin/kit';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type Office = {
    id: number;
    code: string;
    name: string;
    email: string | null;
    phone: string | null;
    address_line_1: string | null;
    city: string | null;
    state: string | null;
    country_code: string;
    timezone: string;
    status: string;
    is_primary: boolean;
    review_workflow: string;
    fiscal_internal_lead_days: number;
    fiscal_review_lead_days: number;
    fiscal_close_policy: string;
    payment_approval_tiers: { up_to_cents: number | null; approvals: number }[];
    payment_self_approval: boolean;
    treasury_close_policy: string;
    receivables_aging_buckets: number[];
    receivables_close_policy: string;
};

type Integration = {
    key: string;
    name: string;
    status: 'ok' | 'warning';
    detail: string;
};

type Tier = { up_to: string; approvals: string };

type Modal =
    | 'data'
    | 'fiscal'
    | 'payments'
    | 'closing'
    | 'receivables'
    | 'integrations'
    | 'offices'
    | null;

const policyLabel = (value: string): string =>
    value === 'block' ? 'bloquear el cierre' : 'solo advertir';

const label = 'block text-sm font-semibold text-[#061633]';

function Field({
    title,
    hint,
    children,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <label className={label}>
            {title}
            <span className="mt-1 block">{children}</span>
            {hint && (
                <span className="text-xs font-normal text-[#526b92]">
                    {hint}
                </span>
            )}
        </label>
    );
}

function PolicySelect({
    title,
    value,
    onChange,
    disabled,
    hint,
}: {
    title: string;
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    hint: string;
}) {
    return (
        <Field title={title} hint={hint}>
            <select
                className={input}
                value={value}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="warn">Solo advertir</option>
                <option value="block">Bloquear el cierre</option>
            </select>
        </Field>
    );
}

function SettingCard({
    icon: Icon,
    title,
    description,
    summary,
    action,
    onClick,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    summary: ReactNode;
    action: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="group flex flex-col rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:border-[#8a0b0b]/60 hover:shadow-md"
        >
            <span className="flex items-start gap-4">
                <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-[#fbe9e9] text-[#8a0b0b]">
                    <Icon className="size-7" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block text-lg font-bold text-[#061633]">
                        {title}
                    </span>
                    <span className="block text-sm text-[#526b92]">
                        {description}
                    </span>
                </span>
                <ChevronRight className="size-5 shrink-0 text-slate-400 transition group-hover:translate-x-0.5" />
            </span>
            <span className="my-4 h-px bg-slate-200" />
            <span className="min-h-10 text-sm text-slate-600">{summary}</span>
            <span className="mt-3 inline-flex items-center gap-2 text-sm font-bold text-[#8a0b0b]">
                {action} <ArrowRight className="size-4" />
            </span>
        </button>
    );
}

type ClientEmail = {
    email: string | null;
    verified: boolean;
    requested_at: string | null;
    canManage: boolean;
};

/**
 * The firm's address for its clients' replies. The firm only types it and confirms it from its inbox: messages leave from
 * Atlantia's sending address under the company's name, and replies reach this address.
 */
function ClientEmailCard({ clientEmail }: { clientEmail: ClientEmail }) {
    const form = useForm({ email: clientEmail.email ?? '' });
    const [editing, setEditing] = useState(clientEmail.email === null);
    const pending = clientEmail.email !== null && !clientEmail.verified;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/office/client-email', {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <section className={`${panel} mb-5 p-5`}>
            <div className="flex flex-wrap items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-[#fbe9e9] text-[#8a0b0b]">
                    <Mail className="size-6" />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg font-bold text-[#061633]">
                        Correo para tus clientes
                    </h2>
                    <p className="text-sm text-[#526b92]">
                        Los recordatorios de cobro, estados de cuenta y facturas
                        salen con el nombre de cada empresa y, cuando el cliente
                        responde, la respuesta llega a este correo. Solo escribe
                        tu correo y confírmalo; no hay nada más que configurar.
                    </p>

                    {!editing && clientEmail.email !== null ? (
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <span className="font-semibold text-[#061633]">
                                {clientEmail.email}
                            </span>
                            {clientEmail.verified ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700">
                                    <CheckCircle2 className="size-3.5" />{' '}
                                    Confirmado
                                </span>
                            ) : (
                                <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700">
                                    Pendiente de confirmar
                                </span>
                            )}
                        </div>
                    ) : null}

                    {pending && !editing && (
                        <p className="mt-2 text-sm text-amber-800">
                            Te enviamos un enlace a {clientEmail.email}. Ábrelo
                            para activarlo; mientras tanto, las respuestas
                            llegan a quien envía cada mensaje.
                        </p>
                    )}

                    {clientEmail.canManage &&
                        (editing ? (
                            <form
                                onSubmit={submit}
                                className="mt-4 flex flex-wrap items-start gap-2"
                            >
                                <label className="min-w-64 flex-1 text-sm font-semibold">
                                    <span className="sr-only">
                                        Correo de la firma
                                    </span>
                                    <input
                                        type="email"
                                        required
                                        className={input}
                                        placeholder="cobros@tufirma.com"
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData(
                                                'email',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.email && (
                                        <span className="mt-1 block text-xs text-red-700">
                                            {form.errors.email}
                                        </span>
                                    )}
                                </label>
                                <button
                                    type="submit"
                                    className={primaryButton}
                                    disabled={form.processing}
                                >
                                    Enviar enlace de confirmación
                                </button>
                                {clientEmail.email !== null && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() => setEditing(false)}
                                    >
                                        Cancelar
                                    </button>
                                )}
                            </form>
                        ) : (
                            <div className="mt-4 flex flex-wrap gap-2">
                                {clientEmail.verified && (
                                    <button
                                        type="button"
                                        className={primaryButton}
                                        onClick={() =>
                                            router.post(
                                                '/office/client-email/test',
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Enviarme un correo de prueba
                                    </button>
                                )}
                                {pending && (
                                    <button
                                        type="button"
                                        className={secondaryButton}
                                        onClick={() =>
                                            router.post(
                                                '/office/client-email',
                                                { email: clientEmail.email },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Reenviar enlace
                                    </button>
                                )}
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    onClick={() => setEditing(true)}
                                >
                                    Cambiar correo
                                </button>
                                <button
                                    type="button"
                                    className={secondaryButton}
                                    onClick={() =>
                                        router.delete('/office/client-email', {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Quitar
                                </button>
                            </div>
                        ))}
                </div>
            </div>
        </section>
    );
}

export default function OfficeSettings({
    offices,
    integrations,
    organization,
    permissions,
    clientEmail,
    notice,
}: {
    offices: Office[];
    integrations: Integration[];
    organization: { name: string; timezone: string };
    permissions: Record<string, boolean>;
    clientEmail: ClientEmail;
    notice: string | null;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(
        offices[0]?.id ?? null,
    );
    const [modal, setModal] = useState<Modal>(null);
    const office = offices.find((item) => item.id === selectedId) ?? offices[0];

    return (
        <>
            <Head title="Configuración de oficina" />
            <Shell>
                <Notice message={notice} />
                <PageHeader
                    title="Configuración"
                    subtitle={`${organization.name} · administra tu sede y las reglas de operación`}
                />
                <ClientEmailCard clientEmail={clientEmail} />
                {office ? (
                    <OfficeBoard
                        key={office.id}
                        office={office}
                        offices={offices}
                        integrations={integrations}
                        permissions={permissions}
                        modal={modal}
                        setModal={setModal}
                        onSelect={(id) => {
                            setSelectedId(id);
                            setModal(null);
                        }}
                    />
                ) : (
                    <p className={`${panel} p-6 text-center text-slate-500`}>
                        No hay sedes en este alcance.
                    </p>
                )}
            </Shell>
        </>
    );
}

function OfficeBoard({
    office,
    offices,
    integrations,
    permissions,
    modal,
    setModal,
    onSelect,
}: {
    office: Office;
    offices: Office[];
    integrations: Integration[];
    permissions: Record<string, boolean>;
    modal: Modal;
    setModal: (modal: Modal) => void;
    onSelect: (id: number) => void;
}) {
    const officeQuery = useOfficeQuery();
    const canPolicies = permissions.canManagePolicies;
    const policies = useForm({
        review_workflow: office.review_workflow,
        fiscal_internal_lead_days: String(office.fiscal_internal_lead_days),
        fiscal_review_lead_days: String(office.fiscal_review_lead_days),
        fiscal_close_policy: office.fiscal_close_policy,
        payment_approval_tiers: office.payment_approval_tiers.map(
            (tier): Tier => ({
                up_to:
                    tier.up_to_cents === null
                        ? ''
                        : String(tier.up_to_cents / 100),
                approvals: String(tier.approvals),
            }),
        ),
        payment_self_approval: office.payment_self_approval,
        treasury_close_policy: office.treasury_close_policy,
        receivables_aging_buckets: office.receivables_aging_buckets.join(', '),
        receivables_close_policy: office.receivables_close_policy,
    });
    const tiers = policies.data.payment_approval_tiers;
    const setTier = (index: number, key: keyof Tier, value: string) =>
        policies.setData(
            'payment_approval_tiers',
            tiers.map((tier, position) =>
                position === index ? { ...tier, [key]: value } : tier,
            ),
        );
    const savePolicies = (event: FormEvent) => {
        event.preventDefault();
        policies.transform((data) => ({
            ...data,
            receivables_aging_buckets: data.receivables_aging_buckets
                .split(',')
                .map((value) => value.trim())
                .filter((value) => value !== ''),
        }));
        policies.patch(`/office/offices/${office.id}/policies`, {
            preserveScroll: true,
            onSuccess: () => setModal(null),
        });
    };
    const closeModal = () => {
        policies.reset();
        policies.clearErrors();
        setModal(null);
    };

    const data = useForm({
        code: office.code,
        name: office.name,
        email: office.email ?? '',
        phone: office.phone ?? '',
        address_line_1: office.address_line_1 ?? '',
        city: office.city ?? '',
        state: office.state ?? '',
        country_code: office.country_code,
        timezone: office.timezone,
        status: office.status,
        is_primary: office.is_primary,
    });
    const saveData = (event: FormEvent) => {
        event.preventDefault();
        data.patch(`/office/offices/${office.id}`, {
            preserveScroll: true,
            onSuccess: () => setModal(null),
        });
    };
    const textField = (
        key:
            | 'name'
            | 'code'
            | 'email'
            | 'phone'
            | 'address_line_1'
            | 'city'
            | 'state'
            | 'timezone',
        title: string,
    ) => (
        <Field title={title}>
            <input
                className={input}
                value={data.data[key]}
                disabled={!permissions.canManageOffices}
                onChange={(event) => data.setData(key, event.target.value)}
            />
            {data.errors[key] && (
                <span className="text-xs text-red-700">{data.errors[key]}</span>
            )}
        </Field>
    );

    const okCount = integrations.filter((item) => item.status === 'ok').length;
    const pending = integrations.filter((item) => item.status === 'warning');
    const buckets = office.receivables_aging_buckets;
    const approvalSummary =
        office.payment_approval_tiers.length === 0
            ? '1 aprobación por defecto'
            : `${office.payment_approval_tiers.length} tramo${office.payment_approval_tiers.length === 1 ? '' : 's'} · hasta ${Math.max(...office.payment_approval_tiers.map((tier) => tier.approvals))} aprobaciones`;
    const errors = Object.values(policies.errors).join(' ');
    const footer = (
        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 pt-4">
            {errors && <p className="mr-auto text-xs text-red-700">{errors}</p>}
            <button
                type="button"
                onClick={closeModal}
                className="inline-flex h-10 items-center rounded-xl border border-[#8a0b0b] px-5 text-sm font-bold text-[#8a0b0b]"
            >
                {canPolicies ? 'Cancelar' : 'Cerrar'}
            </button>
            {canPolicies && (
                <button
                    type="submit"
                    className={primaryButton}
                    disabled={policies.processing}
                >
                    Guardar cambios
                </button>
            )}
        </div>
    );

    return (
        <>
            <section
                className={`${panel} flex flex-wrap items-center gap-4 p-4`}
            >
                <span className="grid size-14 place-items-center rounded-2xl bg-[#fbe9e9] text-[#8a0b0b]">
                    <Building2 className="size-7" />
                </span>
                <div>
                    <div className="flex flex-wrap items-center gap-3">
                        <h2 className="text-2xl font-black text-[#061633]">
                            {office.name}
                        </h2>
                        <span
                            className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ${office.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}
                        >
                            <span className="size-2.5 rounded-full bg-current" />
                            {office.status === 'active' ? 'Activa' : 'Inactiva'}
                        </span>
                    </div>
                    <p className="text-sm text-[#526b92]">
                        {office.code}
                        {office.city && ` · ${office.city}`}
                    </p>
                </div>
                {offices.length > 1 && (
                    <button
                        type="button"
                        onClick={() => setModal('offices')}
                        className="ml-auto inline-flex h-11 items-center gap-2 rounded-xl border border-slate-300 bg-white px-5 text-sm font-bold"
                    >
                        <ArrowLeftRight className="size-4" /> Cambiar sede
                    </button>
                )}
            </section>

            <h2 className="text-xl font-black text-[#061633]">
                Configuración de la sede
            </h2>
            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <SettingCard
                    icon={Building2}
                    title="Datos de la sede"
                    description="Información, contacto y ubicación."
                    summary={`${office.code} · ${office.timezone}`}
                    action={
                        permissions.canManageOffices
                            ? 'Editar datos'
                            : 'Ver datos'
                    }
                    onClick={() => setModal('data')}
                />
                <SettingCard
                    icon={CalendarDays}
                    title="Revisión y calendario fiscal"
                    description="Aprobaciones contables y plazos fiscales."
                    summary={`${office.review_workflow === 'double_level' ? '2 niveles' : '1 nivel'} · Preparación ${office.fiscal_internal_lead_days} días · Revisión ${office.fiscal_review_lead_days} días`}
                    action="Configurar"
                    onClick={() => setModal('fiscal')}
                />
                <SettingCard
                    icon={CreditCard}
                    title="Aprobación de pagos"
                    description="Define aprobaciones según el monto."
                    summary={approvalSummary}
                    action="Gestionar reglas"
                    onClick={() => setModal('payments')}
                />
                <SettingCard
                    icon={FileText}
                    title="Reglas de cierre"
                    description="Controla pendientes fiscales y de tesorería."
                    summary={
                        office.fiscal_close_policy ===
                        office.treasury_close_policy
                            ? `Pendientes: ${policyLabel(office.fiscal_close_policy)}`
                            : `Fiscal: ${policyLabel(office.fiscal_close_policy)} · Tesorería: ${policyLabel(office.treasury_close_policy)}`
                    }
                    action="Configurar"
                    onClick={() => setModal('closing')}
                />
                <SettingCard
                    icon={BarChart3}
                    title="Cuentas por cobrar"
                    description="Antigüedad de saldos y diferencias contables."
                    summary={`Tramos: ${buckets.length > 1 ? `${buckets.slice(0, -1).join(', ')} y ${buckets[buckets.length - 1]}` : (buckets[0] ?? '—')} días`}
                    action="Configurar"
                    onClick={() => setModal('receivables')}
                />
                <SettingCard
                    icon={Plug}
                    title="Servicios e integraciones"
                    description="Consulta el estado de los servicios."
                    summary={
                        <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span className="inline-flex items-center gap-2">
                                <i className="size-2.5 rounded-full bg-emerald-500" />
                                {okCount} operativos
                            </span>
                            {pending.length > 0 && (
                                <span className="inline-flex items-center gap-2">
                                    <i className="size-2.5 rounded-full bg-amber-500" />
                                    {pending.length === 1
                                        ? `${pending[0].name.replace(/\s*\(.*\)/, '')} pendiente`
                                        : `${pending.length} pendientes`}
                                </span>
                            )}
                        </span>
                    }
                    action="Ver estado"
                    onClick={() => setModal('integrations')}
                />
            </section>

            <h2 className="text-xl font-black text-[#061633]">
                Accesos relacionados
            </h2>
            <section className="grid gap-3 md:grid-cols-3">
                {[
                    {
                        icon: Shield,
                        text: 'Seguridad y 2FA',
                        href: '/office/security',
                    },
                    {
                        icon: CreditCard,
                        text: 'Plan y suscripción',
                        href: '/office/subscription',
                    },
                    {
                        icon: Code2,
                        text: 'API y webhooks',
                        href: '/office/integrations',
                    },
                ].map(({ icon: Icon, text, href }) => (
                    <Link
                        key={text}
                        href={scoped(href, officeQuery)}
                        className="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 font-semibold shadow-sm transition hover:border-[#8a0b0b]/60"
                    >
                        <Icon className="size-6 text-[#8a0b0b]" />
                        <span className="flex-1">{text}</span>
                        <ChevronRight className="size-5 text-slate-400" />
                    </Link>
                ))}
            </section>
            <p className="text-sm text-[#526b92]">
                Selecciona una categoría para editar sus opciones.
            </p>

            <Dialog
                open={modal === 'data'}
                onOpenChange={(open) => !open && setModal(null)}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-3xl">
                    <DialogTitle>Datos de la sede</DialogTitle>
                    <DialogDescription>
                        Información, contacto y ubicación de {office.name}.
                    </DialogDescription>
                    <form onSubmit={saveData} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            {textField('name', 'Nombre')}
                            {textField('code', 'Código')}
                            {textField('email', 'Correo')}
                            {textField('phone', 'Teléfono')}
                            {textField('address_line_1', 'Dirección')}
                            {textField('city', 'Ciudad')}
                            {textField('state', 'Departamento')}
                            {textField('timezone', 'Zona horaria')}
                            <Field title="Estado">
                                <select
                                    className={input}
                                    value={data.data.status}
                                    disabled={!permissions.canManageOffices}
                                    onChange={(event) =>
                                        data.setData(
                                            'status',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="active">Activa</option>
                                    <option value="inactive">Inactiva</option>
                                </select>
                                {data.errors.status && (
                                    <span className="text-xs text-red-700">
                                        {data.errors.status}
                                    </span>
                                )}
                            </Field>
                        </div>
                        <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                            <button
                                type="button"
                                onClick={() => {
                                    data.reset();
                                    setModal(null);
                                }}
                                className="inline-flex h-10 items-center rounded-xl border border-[#8a0b0b] px-5 text-sm font-bold text-[#8a0b0b]"
                            >
                                {permissions.canManageOffices
                                    ? 'Cancelar'
                                    : 'Cerrar'}
                            </button>
                            {permissions.canManageOffices && (
                                <button
                                    type="submit"
                                    className={primaryButton}
                                    disabled={data.processing}
                                >
                                    Guardar cambios
                                </button>
                            )}
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={modal === 'fiscal'}
                onOpenChange={(open) => !open && closeModal()}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
                    <DialogTitle>Revisión y calendario fiscal</DialogTitle>
                    <DialogDescription>
                        Cuántos niveles aprueban y con cuántos días de
                        anticipación se prepara y revisa cada obligación.
                    </DialogDescription>
                    <form onSubmit={savePolicies} className="space-y-4">
                        <Field title="Revisión contable">
                            <select
                                className={input}
                                value={policies.data.review_workflow}
                                disabled={!canPolicies}
                                onChange={(event) =>
                                    policies.setData(
                                        'review_workflow',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="single_level">Un nivel</option>
                                <option value="double_level">
                                    Dos niveles (revisión superior)
                                </option>
                            </select>
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field title="Días de preparación antes del vencimiento">
                                <input
                                    type="number"
                                    min={0}
                                    max={30}
                                    className={input}
                                    value={
                                        policies.data.fiscal_internal_lead_days
                                    }
                                    disabled={!canPolicies}
                                    onChange={(event) =>
                                        policies.setData(
                                            'fiscal_internal_lead_days',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field title="Días de revisión antes del vencimiento">
                                <input
                                    type="number"
                                    min={0}
                                    max={30}
                                    className={input}
                                    value={
                                        policies.data.fiscal_review_lead_days
                                    }
                                    disabled={!canPolicies}
                                    onChange={(event) =>
                                        policies.setData(
                                            'fiscal_review_lead_days',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        {footer}
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={modal === 'payments'}
                onOpenChange={(open) => !open && closeModal()}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
                    <DialogTitle>Aprobación de pagos</DialogTitle>
                    <DialogDescription>
                        Cada tramo indica cuántas aprobaciones distintas
                        requiere un pago hasta ese monto; deja el límite vacío
                        para el último tramo. Sin tramos se exige una
                        aprobación.
                    </DialogDescription>
                    <form onSubmit={savePolicies} className="space-y-4">
                        <div className="space-y-3">
                            {tiers.map((tier, index) => (
                                <div
                                    key={index}
                                    className="flex flex-wrap items-end gap-2"
                                >
                                    <Field title="Hasta (Q)">
                                        <input
                                            className={`${input} w-44`}
                                            inputMode="decimal"
                                            placeholder="Sin límite"
                                            value={tier.up_to}
                                            disabled={!canPolicies}
                                            onChange={(event) =>
                                                setTier(
                                                    index,
                                                    'up_to',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field title="Aprobaciones">
                                        <select
                                            className={`${input} w-32`}
                                            value={tier.approvals}
                                            disabled={!canPolicies}
                                            onChange={(event) =>
                                                setTier(
                                                    index,
                                                    'approvals',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            {['1', '2', '3'].map((value) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    {canPolicies && (
                                        <button
                                            type="button"
                                            className={secondaryButton}
                                            aria-label="Quitar tramo"
                                            onClick={() =>
                                                policies.setData(
                                                    'payment_approval_tiers',
                                                    tiers.filter(
                                                        (_, position) =>
                                                            position !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    )}
                                </div>
                            ))}
                            {tiers.length === 0 && (
                                <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                                    Sin tramos: todo pago requiere una
                                    aprobación.
                                </p>
                            )}
                            {canPolicies && tiers.length < 5 && (
                                <button
                                    type="button"
                                    className={`${secondaryButton} w-fit`}
                                    onClick={() =>
                                        policies.setData(
                                            'payment_approval_tiers',
                                            [
                                                ...tiers,
                                                { up_to: '', approvals: '1' },
                                            ],
                                        )
                                    }
                                >
                                    <Plus className="size-4" /> Agregar tramo
                                </button>
                            )}
                        </div>
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-0.5 size-4 accent-[#640000]"
                                checked={policies.data.payment_self_approval}
                                disabled={!canPolicies}
                                onChange={(event) =>
                                    policies.setData(
                                        'payment_self_approval',
                                        event.target.checked,
                                    )
                                }
                            />
                            Permitir que quien prepara un pago también lo
                            apruebe (no recomendado)
                        </label>
                        {footer}
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={modal === 'closing'}
                onOpenChange={(open) => !open && closeModal()}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
                    <DialogTitle>Reglas de cierre</DialogTitle>
                    <DialogDescription>
                        Qué hace el cierre del período cuando quedan pendientes
                        fiscales o de tesorería.
                    </DialogDescription>
                    <form onSubmit={savePolicies} className="space-y-4">
                        <PolicySelect
                            title="Fiscal pendiente al cierre"
                            value={policies.data.fiscal_close_policy}
                            disabled={!canPolicies}
                            hint="Obligaciones sin aprobar o FEL con error."
                            onChange={(value) =>
                                policies.setData('fiscal_close_policy', value)
                            }
                        />
                        <PolicySelect
                            title="Tesorería pendiente al cierre"
                            value={policies.data.treasury_close_policy}
                            disabled={!canPolicies}
                            hint="Cuentas sin conciliación aprobada o movimientos sin conciliar."
                            onChange={(value) =>
                                policies.setData('treasury_close_policy', value)
                            }
                        />
                        {footer}
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={modal === 'receivables'}
                onOpenChange={(open) => !open && closeModal()}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
                    <DialogTitle>Cuentas por cobrar</DialogTitle>
                    <DialogDescription>
                        Antigüedad de saldos y diferencias contables al cierre.
                    </DialogDescription>
                    <form onSubmit={savePolicies} className="space-y-4">
                        <Field
                            title="Tramos de antigüedad (días)"
                            hint="Límites separados por coma; el último tramo es “más de”."
                        >
                            <input
                                className={input}
                                placeholder="30, 60, 90"
                                value={policies.data.receivables_aging_buckets}
                                disabled={!canPolicies}
                                onChange={(event) =>
                                    policies.setData(
                                        'receivables_aging_buckets',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <PolicySelect
                            title="Diferencia CxC vs Mayor al cierre"
                            value={policies.data.receivables_close_policy}
                            disabled={!canPolicies}
                            hint="Tener clientes con saldo nunca bloquea; solo las inconsistencias."
                            onChange={(value) =>
                                policies.setData(
                                    'receivables_close_policy',
                                    value,
                                )
                            }
                        />
                        {footer}
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={modal === 'integrations'}
                onOpenChange={(open) => !open && setModal(null)}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
                    <DialogTitle>Servicios e integraciones</DialogTitle>
                    <DialogDescription>
                        Estado de los servicios que usa la oficina. La
                        facturación electrónica se configura por empresa.
                    </DialogDescription>
                    <div className="space-y-2">
                        {integrations.map((integration) => (
                            <div
                                key={integration.key}
                                className="flex items-start gap-3 rounded-xl border border-slate-100 p-3"
                            >
                                {integration.status === 'ok' ? (
                                    <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />
                                ) : (
                                    <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600" />
                                )}
                                <div>
                                    <p className="font-bold">
                                        {integration.name}
                                    </p>
                                    <p className="text-sm text-[#526b92]">
                                        {integration.detail}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="flex justify-end border-t border-slate-200 pt-4">
                        <Link
                            href={scoped('/office/integrations', officeQuery)}
                            className={primaryButton}
                        >
                            Ir a Integraciones
                        </Link>
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog
                open={modal === 'offices'}
                onOpenChange={(open) => !open && setModal(null)}
            >
                <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-lg">
                    <DialogTitle>Cambiar sede</DialogTitle>
                    <DialogDescription>
                        Elige la sede que quieres configurar.
                    </DialogDescription>
                    <ul className="space-y-2">
                        {offices.map((item) => (
                            <li key={item.id}>
                                <button
                                    type="button"
                                    onClick={() => onSelect(item.id)}
                                    className={`flex w-full items-center justify-between rounded-xl border p-3 text-left font-semibold transition hover:border-[#8a0b0b]/60 ${item.id === office.id ? 'border-[#8a0b0b] bg-[#fbe9e9]' : 'border-slate-200'}`}
                                >
                                    <span>
                                        {item.name}
                                        <span className="block text-xs font-normal text-[#526b92]">
                                            {item.code}
                                            {item.city && ` · ${item.city}`}
                                        </span>
                                    </span>
                                    {item.id === office.id && (
                                        <CheckCircle2 className="size-5 text-[#8a0b0b]" />
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}
