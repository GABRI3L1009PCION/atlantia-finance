import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CalendarClock,
    CircleDollarSign,
    Eye,
    HandCoins,
    History,
    MessageSquarePlus,
    Pencil,
    Plus,
    RefreshCcw,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    TableFilterSelect,
    TablePagination,
    TableToolbar,
    useTableControls,
} from '@/components/table-controls';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type Row = {
    kind: 'client' | 'prospect';
    id: number;
    name: string;
    location: string | null;
    office: string | null;
    owner: string | null;
    status:
        | 'active'
        | 'onboarding'
        | 'at_risk'
        | 'cancelled'
        | 'no_contract'
        | 'prospect';
    services: string | null;
    fee_cents: number;
    renews_on: string | null;
    risk: 'low' | 'medium' | 'high' | null;
    stage?: string;
};

type Props = {
    summary: {
        clients: number;
        prospects: number;
        onboarding: number;
        at_risk: number;
        retention: number | null;
        mrr_cents: number;
    };
    pipeline: {
        stage: string;
        label: string;
        count: number;
        value_cents: number;
    }[];
    by_office: { name: string; value: number }[];
    by_owner: { name: string; value: number }[];
    rows: Row[];
    offices: string[];
    alerts: {
        tone: 'red' | 'amber' | 'blue';
        title: string;
        detail: string;
        count: number;
    }[];
    renewals: { company: string; services: string; renews_on: string }[];
    interactions: {
        user: string;
        about: string | null;
        summary: string;
        kind: string;
        at: string;
    }[];
    companies: { id: number; name: string }[];
    prospect_options: { id: number; name: string; stage: string }[];
    staff: { id: number; name: string }[];
    stages: Record<string, string>;
    canManage: boolean;
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const field =
    'h-10 w-full rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';
const button =
    'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#8a0b0b] px-4 text-sm font-bold text-white disabled:opacity-60';
const STATUS = {
    active: {
        label: 'Cliente activo',
        style: 'bg-emerald-50 text-emerald-700',
    },
    onboarding: { label: 'En onboarding', style: 'bg-blue-50 text-blue-700' },
    at_risk: { label: 'Cliente en riesgo', style: 'bg-red-50 text-red-700' },
    cancelled: { label: 'Cancelado', style: 'bg-slate-100 text-slate-600' },
    no_contract: { label: 'Sin contrato', style: 'bg-amber-50 text-amber-700' },
    prospect: { label: 'Prospecto', style: 'bg-violet-50 text-violet-700' },
} as const;
const RISK = {
    low: { label: 'Bajo', style: 'bg-emerald-50 text-emerald-700' },
    medium: { label: 'Medio', style: 'bg-amber-50 text-amber-700' },
    high: { label: 'Alto', style: 'bg-red-50 text-red-700' },
} as const;
const SHADES = ['#c98a8a', '#d9a0a0', '#e8bcbc', '#f0d0d0', '#8a0b0b'];
const PIE = ['#8a0b0b', '#c0454a', '#e8918f', '#f1b8b6', '#f6d4d2'];
const KIND = {
    meeting: 'Reunión',
    email: 'Correo',
    call: 'Llamada',
    note: 'Nota',
} as const;

const money = (cents: number) =>
    `Q${Math.round(cents / 100).toLocaleString('es-GT')}`;
const formatDate = (value: string | null) =>
    value
        ? new Date(
              value.length === 10 ? `${value}T12:00:00` : value,
          ).toLocaleDateString('es-GT', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

function Kpi({
    icon,
    tone,
    label,
    value,
    hint,
    bad,
}: {
    icon: ReactNode;
    tone: string;
    label: string;
    value: string;
    hint: string;
    bad?: boolean;
}) {
    return (
        <div className={`${card} flex min-w-0 items-center gap-3 !p-4`}>
            <span
                className={`grid size-12 shrink-0 place-items-center rounded-2xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm leading-tight font-bold text-[#8a0b0b]">
                    {label}
                </p>
                <p className="truncate text-2xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p
                    className={`text-xs leading-tight font-semibold ${bad ? 'text-red-600' : 'text-slate-500'}`}
                >
                    {hint}
                </p>
            </div>
        </div>
    );
}

function Pipeline({ stages }: { stages: Props['pipeline'] }) {
    const max = Math.max(1, ...stages.map((stage) => stage.count));
    const first = stages[0]?.count ?? 0;

    return (
        <div className="flex items-end justify-between gap-2 pt-4">
            {stages.map((stage, index) => (
                <div key={stage.stage} className="flex flex-1 items-end gap-1">
                    <div className="flex flex-1 flex-col items-center">
                        <span className="mb-1 text-sm font-black">
                            {stage.count}
                        </span>
                        <div
                            className="w-full max-w-16 rounded-t-md"
                            style={{
                                height: `${Math.max(8, (stage.count / max) * 110)}px`,
                                background:
                                    SHADES[Math.min(index, SHADES.length - 1)],
                            }}
                        />
                        <span className="mt-2 text-center text-xs font-semibold">
                            {stage.label}
                        </span>
                        <span className="text-xs text-slate-500">
                            {money(stage.value_cents)}
                        </span>
                        <span className="text-xs font-bold text-[#8a0b0b]">
                            {first
                                ? Math.round((stage.count / first) * 100)
                                : 0}
                            %
                        </span>
                    </div>
                    {index < stages.length - 1 && (
                        <ArrowRight className="mb-16 size-4 shrink-0 text-slate-300" />
                    )}
                </div>
            ))}
        </div>
    );
}

function OfficeDonut({ rows }: { rows: Props['by_office'] }) {
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    const radius = 50;
    const circumference = 2 * Math.PI * radius;
    const lengths = rows.map((row) =>
        total > 0 ? (row.value / total) * circumference : 0,
    );

    return (
        <div className="flex items-center gap-4">
            <svg viewBox="0 0 130 130" className="size-36 shrink-0">
                <circle
                    cx="65"
                    cy="65"
                    r={radius}
                    fill="none"
                    stroke="#eef1f3"
                    strokeWidth="18"
                />
                {rows.map((row, i) => (
                    <circle
                        key={row.name}
                        cx="65"
                        cy="65"
                        r={radius}
                        fill="none"
                        stroke={PIE[i % PIE.length]}
                        strokeWidth="18"
                        strokeDasharray={`${lengths[i]} ${circumference - lengths[i]}`}
                        strokeDashoffset={
                            -lengths.slice(0, i).reduce((s, v) => s + v, 0)
                        }
                        transform="rotate(-90 65 65)"
                    />
                ))}
                <text
                    x="65"
                    y="64"
                    textAnchor="middle"
                    className="fill-[#061633] text-[22px] font-black"
                >
                    {total}
                </text>
                <text
                    x="65"
                    y="80"
                    textAnchor="middle"
                    className="fill-slate-500 text-[10px]"
                >
                    empresas
                </text>
            </svg>
            <ul className="min-w-0 flex-1 space-y-1.5 text-sm">
                {rows.map((row, i) => (
                    <li key={row.name} className="flex items-center gap-2">
                        <i
                            className="size-2.5 shrink-0 rounded-full"
                            style={{ background: PIE[i % PIE.length] }}
                        />
                        <span className="flex-1 truncate">{row.name}</span>
                        <b>{row.value}</b>
                        <span className="w-14 text-right text-xs text-slate-500">
                            (
                            {total
                                ? Math.round((row.value / total) * 1000) / 10
                                : 0}
                            %)
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

type CrmDialog =
    | { kind: 'prospect' }
    | { kind: 'contract'; companyId?: string }
    | { kind: 'interaction'; companyId?: string; prospectId?: string }
    | { kind: 'pipeline' }
    | { kind: 'detail'; row: Row }
    | { kind: 'renewals' }
    | { kind: 'interactions' }
    | null;

const label = 'block text-sm font-semibold text-[#061633]';
const iconButton =
    'inline-grid size-9 place-items-center rounded-lg border border-slate-300 bg-white text-[#8a0b0b] transition hover:border-[#c9a36a] hover:bg-amber-50';

type Submittable = {
    post: (url: string, options: object) => void;
    reset: () => void;
};

function useSubmit(onDone: () => void) {
    return (form: Submittable, url: string) => (event: FormEvent) => {
        event.preventDefault();
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };
}

function FormActions({
    processing,
    onCancel,
    children,
}: {
    processing: boolean;
    onCancel: () => void;
    children: ReactNode;
}) {
    return (
        <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
            <button
                type="button"
                onClick={onCancel}
                className="inline-flex h-10 items-center rounded-xl border border-[#8a0b0b] px-5 text-sm font-bold text-[#8a0b0b]"
            >
                Cancelar
            </button>
            <button className={button} disabled={processing}>
                {children}
            </button>
        </div>
    );
}

function ProspectForm({
    onDone,
    onCancel,
}: {
    onDone: () => void;
    onCancel: () => void;
}) {
    const prospect = useForm({
        name: '',
        contact_name: '',
        contact_email: '',
        stage: 'prospect',
        estimated_value: '',
        expected_close_on: '',
    });
    const submit = useSubmit(onDone);

    return (
        <form
            onSubmit={submit(prospect, '/office/crm/prospects')}
            className="space-y-4"
        >
            <label className={label}>
                Empresa o persona
                <input
                    className={`${field} mt-1`}
                    value={prospect.data.name}
                    onChange={(e) => prospect.setData('name', e.target.value)}
                    required
                />
            </label>
            <div className="grid gap-4 sm:grid-cols-2">
                <label className={label}>
                    Contacto
                    <input
                        className={`${field} mt-1`}
                        value={prospect.data.contact_name}
                        onChange={(e) =>
                            prospect.setData('contact_name', e.target.value)
                        }
                    />
                </label>
                <label className={label}>
                    Correo
                    <input
                        className={`${field} mt-1`}
                        type="email"
                        value={prospect.data.contact_email}
                        onChange={(e) =>
                            prospect.setData('contact_email', e.target.value)
                        }
                    />
                </label>
                <label className={label}>
                    Etapa
                    <select
                        className={`${field} mt-1`}
                        value={prospect.data.stage}
                        onChange={(e) =>
                            prospect.setData('stage', e.target.value)
                        }
                    >
                        <option value="prospect">Prospecto</option>
                        <option value="contact">Contacto</option>
                        <option value="proposal">Propuesta</option>
                        <option value="negotiation">Negociación</option>
                    </select>
                </label>
                <label className={label}>
                    Valor estimado (Q)
                    <input
                        className={`${field} mt-1`}
                        type="number"
                        min="0"
                        value={prospect.data.estimated_value}
                        onChange={(e) =>
                            prospect.setData('estimated_value', e.target.value)
                        }
                    />
                </label>
            </div>
            <label className={label}>
                Cierre esperado
                <input
                    className={`${field} mt-1`}
                    type="date"
                    value={prospect.data.expected_close_on}
                    onChange={(e) =>
                        prospect.setData('expected_close_on', e.target.value)
                    }
                />
            </label>
            <FormActions processing={prospect.processing} onCancel={onCancel}>
                <Plus className="size-4" /> Guardar prospecto
            </FormActions>
        </form>
    );
}

function ContractForm({
    companies,
    staff,
    initialCompany,
    onDone,
    onCancel,
}: Pick<Props, 'companies' | 'staff'> & {
    initialCompany: string;
    onDone: () => void;
    onCancel: () => void;
}) {
    const contract = useForm({
        company_id: initialCompany,
        services: '',
        monthly_fee: '',
        starts_on: '',
        renews_on: '',
        status: 'active',
        owner_id: '',
    });
    const submit = useSubmit(onDone);

    return (
        <form
            onSubmit={submit(contract, '/office/crm/contracts')}
            className="space-y-4"
        >
            <label className={label}>
                Empresa cliente
                <select
                    className={`${field} mt-1`}
                    value={contract.data.company_id}
                    onChange={(e) =>
                        contract.setData('company_id', e.target.value)
                    }
                    required
                >
                    <option value="">Selecciona una empresa</option>
                    {companies.map((company) => (
                        <option key={company.id} value={company.id}>
                            {company.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className={label}>
                Servicios
                <input
                    className={`${field} mt-1`}
                    placeholder="Ej. Contabilidad + Impuestos"
                    value={contract.data.services}
                    onChange={(e) =>
                        contract.setData('services', e.target.value)
                    }
                    required
                />
            </label>
            <div className="grid gap-4 sm:grid-cols-2">
                <label className={label}>
                    Cuota mensual (Q)
                    <input
                        className={`${field} mt-1`}
                        type="number"
                        min="0"
                        step="0.01"
                        value={contract.data.monthly_fee}
                        onChange={(e) =>
                            contract.setData('monthly_fee', e.target.value)
                        }
                        required
                    />
                </label>
                <label className={label}>
                    Estado
                    <select
                        className={`${field} mt-1`}
                        value={contract.data.status}
                        onChange={(e) =>
                            contract.setData('status', e.target.value)
                        }
                    >
                        <option value="active">Activo</option>
                        <option value="onboarding">En onboarding</option>
                        <option value="at_risk">En riesgo</option>
                        <option value="cancelled">Cancelado</option>
                    </select>
                </label>
                <label className={label}>
                    Inicio
                    <input
                        className={`${field} mt-1`}
                        type="date"
                        value={contract.data.starts_on}
                        onChange={(e) =>
                            contract.setData('starts_on', e.target.value)
                        }
                        required
                    />
                </label>
                <label className={label}>
                    Renueva
                    <input
                        className={`${field} mt-1`}
                        type="date"
                        value={contract.data.renews_on}
                        onChange={(e) =>
                            contract.setData('renews_on', e.target.value)
                        }
                    />
                </label>
            </div>
            <label className={label}>
                Responsable{' '}
                <span className="font-normal text-slate-500">(opcional)</span>
                <select
                    className={`${field} mt-1`}
                    value={contract.data.owner_id}
                    onChange={(e) =>
                        contract.setData('owner_id', e.target.value)
                    }
                >
                    <option value="">Sin responsable</option>
                    {staff.map((person) => (
                        <option key={person.id} value={person.id}>
                            {person.name}
                        </option>
                    ))}
                </select>
            </label>
            <FormActions processing={contract.processing} onCancel={onCancel}>
                <HandCoins className="size-4" /> Guardar contrato
            </FormActions>
        </form>
    );
}

function InteractionForm({
    companies,
    prospectOptions,
    initialCompany,
    initialProspect,
    onDone,
    onCancel,
}: Pick<Props, 'companies'> & {
    prospectOptions: Props['prospect_options'];
    initialCompany: string;
    initialProspect: string;
    onDone: () => void;
    onCancel: () => void;
}) {
    const interaction = useForm({
        company_id: initialCompany,
        crm_prospect_id: initialProspect,
        kind: 'meeting',
        summary: '',
        next_action: '',
        next_action_on: '',
    });
    const submit = useSubmit(onDone);

    return (
        <form
            onSubmit={submit(interaction, '/office/crm/interactions')}
            className="space-y-4"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <label className={label}>
                    Cliente{' '}
                    <span className="font-normal text-slate-500">
                        (opcional)
                    </span>
                    <select
                        className={`${field} mt-1`}
                        value={interaction.data.company_id}
                        onChange={(e) =>
                            interaction.setData('company_id', e.target.value)
                        }
                    >
                        <option value="">Ninguno</option>
                        {companies.map((company) => (
                            <option key={company.id} value={company.id}>
                                {company.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className={label}>
                    Prospecto{' '}
                    <span className="font-normal text-slate-500">
                        (opcional)
                    </span>
                    <select
                        className={`${field} mt-1`}
                        value={interaction.data.crm_prospect_id}
                        onChange={(e) =>
                            interaction.setData(
                                'crm_prospect_id',
                                e.target.value,
                            )
                        }
                    >
                        <option value="">Ninguno</option>
                        {prospectOptions.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <label className={label}>
                Tipo de interacción
                <select
                    className={`${field} mt-1`}
                    value={interaction.data.kind}
                    onChange={(e) =>
                        interaction.setData('kind', e.target.value)
                    }
                >
                    {Object.entries(KIND).map(([key, text]) => (
                        <option key={key} value={key}>
                            {text}
                        </option>
                    ))}
                </select>
            </label>
            <label className={label}>
                Resumen
                <textarea
                    className={`${field} mt-1 h-24 py-2`}
                    value={interaction.data.summary}
                    onChange={(e) =>
                        interaction.setData('summary', e.target.value)
                    }
                    required
                />
            </label>
            <div className="grid gap-4 sm:grid-cols-2">
                <label className={label}>
                    Próxima acción
                    <input
                        className={`${field} mt-1`}
                        value={interaction.data.next_action}
                        onChange={(e) =>
                            interaction.setData('next_action', e.target.value)
                        }
                    />
                </label>
                <label className={label}>
                    Fecha de la próxima acción
                    <input
                        className={`${field} mt-1`}
                        type="date"
                        value={interaction.data.next_action_on}
                        onChange={(e) =>
                            interaction.setData(
                                'next_action_on',
                                e.target.value,
                            )
                        }
                    />
                </label>
            </div>
            <FormActions
                processing={interaction.processing}
                onCancel={onCancel}
            >
                <Plus className="size-4" /> Guardar interacción
            </FormActions>
        </form>
    );
}

function PipelineMover({
    prospectOptions,
    stages,
    onClose,
}: {
    prospectOptions: Props['prospect_options'];
    stages: Props['stages'];
    onClose: () => void;
}) {
    return (
        <div className="space-y-4">
            <ul className="divide-y divide-slate-100 text-sm">
                {prospectOptions.map((item) => (
                    <li
                        key={item.id}
                        className="flex items-center justify-between gap-3 py-2.5"
                    >
                        <span className="font-bold">{item.name}</span>
                        <select
                            className="rounded-lg border border-[#e4dbd6] px-2 py-1.5 text-sm"
                            value={item.stage}
                            onChange={(e) =>
                                router.patch(
                                    `/office/crm/prospects/${item.id}`,
                                    { stage: e.target.value },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {Object.entries(stages).map(([key, text]) => (
                                <option key={key} value={key}>
                                    {text}
                                </option>
                            ))}
                            <option value="lost">Perdido</option>
                        </select>
                    </li>
                ))}
                {prospectOptions.length === 0 && (
                    <li className="py-6 text-center text-slate-500">
                        No hay prospectos abiertos en el pipeline.
                    </li>
                )}
            </ul>
            <div className="flex justify-end border-t border-slate-200 pt-4">
                <button type="button" onClick={onClose} className={button}>
                    Listo
                </button>
            </div>
        </div>
    );
}

function RowDetail({ row, stages }: { row: Row; stages: Props['stages'] }) {
    const items: [string, ReactNode][] = [
        ['Tipo', row.kind === 'client' ? 'Empresa cliente' : 'Prospecto'],
        [
            'Estado',
            <span
                key="status"
                className={`rounded-full px-3 py-1 text-xs font-bold ${STATUS[row.status].style}`}
            >
                {STATUS[row.status].label}
            </span>,
        ],
        [
            row.kind === 'client' ? 'Oficina' : 'Etapa',
            row.kind === 'client'
                ? (row.office ?? '—')
                : row.stage
                  ? (stages[row.stage] ?? row.stage)
                  : '—',
        ],
        ['Responsable', row.owner ?? 'Sin asignar'],
        [
            'Servicio contratado',
            row.services ??
                (row.kind === 'prospect' ? 'Propuesta en revisión' : '—'),
        ],
        [
            row.kind === 'client' ? 'Cuota mensual' : 'Valor estimado',
            row.fee_cents > 0 ? money(row.fee_cents) : '—',
        ],
        [
            row.kind === 'client' ? 'Renovación' : 'Cierre esperado',
            formatDate(row.renews_on),
        ],
        [
            'Riesgo',
            row.risk ? (
                <span
                    key="risk"
                    className={`rounded-md px-2.5 py-1 text-xs font-bold ${RISK[row.risk].style}`}
                >
                    {RISK[row.risk].label}
                </span>
            ) : (
                '—'
            ),
        ],
    ];

    return (
        <dl className="divide-y divide-slate-100 text-sm">
            {items.map(([term, value]) => (
                <div
                    key={term}
                    className="grid grid-cols-[10rem_1fr] items-center gap-3 py-2.5"
                >
                    <dt className="text-slate-500">{term}</dt>
                    <dd className="font-semibold">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

const DIALOG_COPY: Record<
    NonNullable<CrmDialog>['kind'],
    { title: string; description: string }
> = {
    prospect: {
        title: 'Registrar prospecto',
        description: 'Agrega una empresa o persona al pipeline comercial.',
    },
    contract: {
        title: 'Contrato de un cliente',
        description: 'Registra o actualiza el contrato de una empresa cliente.',
    },
    interaction: {
        title: 'Registrar interacción',
        description: 'Deja constancia de una reunión, llamada o correo.',
    },
    pipeline: {
        title: 'Mover prospectos en el pipeline',
        description: 'Cambia la etapa de cada prospecto abierto.',
    },
    detail: {
        title: 'Detalle',
        description: 'Información comercial de este registro.',
    },
    renewals: {
        title: 'Próximas renovaciones',
        description:
            'Contratos que vencen pronto, del más cercano al más lejano.',
    },
    interactions: {
        title: 'Interacciones recientes',
        description:
            'Lo último que el equipo registró con clientes y prospectos.',
    },
};

function RenewalsList({ renewals }: { renewals: Props['renewals'] }) {
    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="text-left text-xs text-slate-500">
                    <th className="py-1">Empresa</th>
                    <th>Servicio</th>
                    <th>Vence</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
                {renewals.map((item) => (
                    <tr key={`${item.company}-${item.renews_on}`}>
                        <td className="py-2.5 font-bold">{item.company}</td>
                        <td className="text-slate-600">{item.services}</td>
                        <td className="font-bold whitespace-nowrap text-[#8a0b0b]">
                            {formatDate(item.renews_on)}
                        </td>
                    </tr>
                ))}
                {renewals.length === 0 && (
                    <tr>
                        <td
                            colSpan={3}
                            className="py-8 text-center text-slate-500"
                        >
                            Sin renovaciones próximas.
                        </td>
                    </tr>
                )}
            </tbody>
        </table>
    );
}

function InteractionsList({
    interactions,
}: {
    interactions: Props['interactions'];
}) {
    return (
        <ul className="space-y-3">
            {interactions.map((item, index) => (
                <li key={index} className="flex items-start gap-3 text-sm">
                    <span className="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full bg-[#f6e6e4] text-xs font-black text-[#8a0b0b]">
                        {item.user.charAt(0)}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="font-black">
                            {item.user}
                            {item.about ? ` · ${item.about}` : ''}
                        </p>
                        <p className="text-xs text-slate-600">
                            {KIND[item.kind as keyof typeof KIND] ?? 'Nota'}:{' '}
                            {item.summary}
                        </p>
                    </div>
                    <span className="shrink-0 text-xs text-slate-400">
                        {formatDate(item.at)}
                    </span>
                </li>
            ))}
            {interactions.length === 0 && (
                <li className="py-6 text-center text-sm text-slate-500">
                    Aún no hay interacciones registradas.
                </li>
            )}
        </ul>
    );
}

function CrmDialogs({
    dialog,
    onClose,
    ...props
}: {
    dialog: CrmDialog;
    onClose: () => void;
} & Pick<
    Props,
    | 'companies'
    | 'staff'
    | 'prospect_options'
    | 'stages'
    | 'renewals'
    | 'interactions'
>) {
    return (
        <Dialog open={dialog !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto bg-white text-[#142135] sm:max-w-2xl">
                {dialog && (
                    <>
                        <DialogTitle>
                            {dialog.kind === 'detail'
                                ? dialog.row.name
                                : DIALOG_COPY[dialog.kind].title}
                        </DialogTitle>
                        <DialogDescription>
                            {DIALOG_COPY[dialog.kind].description}
                        </DialogDescription>
                        {dialog.kind === 'prospect' && (
                            <ProspectForm onDone={onClose} onCancel={onClose} />
                        )}
                        {dialog.kind === 'contract' && (
                            <ContractForm
                                companies={props.companies}
                                staff={props.staff}
                                initialCompany={dialog.companyId ?? ''}
                                onDone={onClose}
                                onCancel={onClose}
                            />
                        )}
                        {dialog.kind === 'interaction' && (
                            <InteractionForm
                                companies={props.companies}
                                prospectOptions={props.prospect_options}
                                initialCompany={dialog.companyId ?? ''}
                                initialProspect={dialog.prospectId ?? ''}
                                onDone={onClose}
                                onCancel={onClose}
                            />
                        )}
                        {dialog.kind === 'pipeline' && (
                            <PipelineMover
                                prospectOptions={props.prospect_options}
                                stages={props.stages}
                                onClose={onClose}
                            />
                        )}
                        {dialog.kind === 'detail' && (
                            <RowDetail row={dialog.row} stages={props.stages} />
                        )}
                        {dialog.kind === 'renewals' && (
                            <RenewalsList renewals={props.renewals} />
                        )}
                        {dialog.kind === 'interactions' && (
                            <InteractionsList
                                interactions={props.interactions}
                            />
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function ManageButtons({
    onOpen,
    hasProspects,
    canManage,
}: {
    onOpen: (dialog: CrmDialog) => void;
    hasProspects: boolean;
    canManage: boolean;
}) {
    const ghost =
        'inline-flex h-10 items-center gap-1.5 rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm font-bold whitespace-nowrap text-[#8a0b0b] transition hover:border-[#c9a36a]';

    return (
        <div className="flex flex-wrap items-center gap-2 xl:flex-nowrap">
            <button
                type="button"
                className={ghost}
                onClick={() => onOpen({ kind: 'renewals' })}
            >
                <CalendarClock className="size-4" /> Próximas renovaciones
            </button>
            <button
                type="button"
                className={ghost}
                onClick={() => onOpen({ kind: 'interactions' })}
            >
                <History className="size-4" /> Interacciones recientes
            </button>
            {canManage && hasProspects && (
                <button
                    type="button"
                    className={ghost}
                    onClick={() => onOpen({ kind: 'pipeline' })}
                >
                    <ArrowRight className="size-4" /> Mover en el pipeline
                </button>
            )}
            {canManage && (
                <>
                    <button
                        type="button"
                        className={ghost}
                        onClick={() => onOpen({ kind: 'interaction' })}
                    >
                        <MessageSquarePlus className="size-4" /> Registrar
                        interacción
                    </button>
                    <button
                        type="button"
                        className={ghost}
                        onClick={() => onOpen({ kind: 'contract' })}
                    >
                        <HandCoins className="size-4" /> Contrato de un cliente
                    </button>
                    <button
                        type="button"
                        className={`${button} !gap-1.5 !px-3 whitespace-nowrap`}
                        onClick={() => onOpen({ kind: 'prospect' })}
                    >
                        <Plus className="size-4" /> Registrar prospecto
                    </button>
                </>
            )}
        </div>
    );
}

export default function Crm(props: Props) {
    const {
        summary,
        pipeline,
        by_office,
        by_owner,
        rows,
        offices,
        stages,
        canManage,
    } = props;
    const [office, setOffice] = useState('');
    const [status, setStatus] = useState('');
    const [dialog, setDialog] = useState<CrmDialog>(null);
    const predicate = useMemo(
        () => (row: Row) =>
            (office === '' || row.office === office) &&
            (status === '' || row.status === status),
        [office, status],
    );
    const table = useTableControls(rows, {
        searchText: (row: Row) =>
            `${row.name} ${row.location ?? ''} ${row.owner ?? ''} ${row.services ?? ''}`,
        predicate,
    });
    const maxOwner = Math.max(1, ...by_owner.map((owner) => owner.value));

    return (
        <>
            <Head title="Clientes (CRM)" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex flex-col gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                                <Users className="size-7" />
                            </span>
                            <div>
                                <h1 className="text-3xl font-black tracking-tight">
                                    Clientes (CRM)
                                </h1>
                                <p className="text-sm text-slate-600">
                                    Relaciones comerciales y seguimiento del
                                    ciclo de vida de cada cliente.
                                </p>
                            </div>
                        </div>
                        <ManageButtons
                            onOpen={setDialog}
                            hasProspects={props.prospect_options.length > 0}
                            canManage={canManage}
                        />
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        <Kpi
                            icon={<Building2 className="size-7" />}
                            tone="bg-[#f6e6e4] text-[#8a0b0b]"
                            label="Empresas cliente"
                            value={String(summary.clients)}
                            hint="En la cartera"
                        />
                        <Kpi
                            icon={<Users className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Prospectos activos"
                            value={String(summary.prospects)}
                            hint="En el pipeline"
                        />
                        <Kpi
                            icon={<HandCoins className="size-7" />}
                            tone="bg-blue-50 text-blue-600"
                            label="En onboarding"
                            value={String(summary.onboarding)}
                            hint="Incorporándose"
                        />
                        <Kpi
                            icon={<AlertTriangle className="size-7" />}
                            tone="bg-red-50 text-red-600"
                            label="Clientes en riesgo"
                            value={String(summary.at_risk)}
                            hint="Requieren atención"
                            bad={summary.at_risk > 0}
                        />
                        <Kpi
                            icon={<RefreshCcw className="size-7" />}
                            tone="bg-green-50 text-green-600"
                            label="Tasa de retención"
                            value={
                                summary.retention === null
                                    ? '—'
                                    : `${summary.retention}%`
                            }
                            hint="Contratos activos vs. cancelados"
                        />
                        <Kpi
                            icon={<CircleDollarSign className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="Ingresos recurrentes (MRR)"
                            value={money(summary.mrr_cents)}
                            hint="Suma de cuotas mensuales activas"
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.5fr_1fr_1fr]">
                        <div className={card}>
                            <h2 className="font-black">Pipeline comercial</h2>
                            <Pipeline stages={pipeline} />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Clientes por oficina
                            </h2>
                            <OfficeDonut rows={by_office} />
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Cartera por responsable
                            </h2>
                            <ul className="space-y-3">
                                {by_owner.map((owner) => (
                                    <li
                                        key={owner.name}
                                        className="grid grid-cols-[minmax(0,7rem)_1fr_auto] items-center gap-3 text-sm"
                                    >
                                        <span className="truncate">
                                            {owner.name}
                                        </span>
                                        <div className="h-4 overflow-hidden rounded bg-slate-100">
                                            <div
                                                className="h-full rounded bg-[#8a0b0b]"
                                                style={{
                                                    width: `${(owner.value / maxOwner) * 100}%`,
                                                }}
                                            />
                                        </div>
                                        <b>{owner.value}</b>
                                    </li>
                                ))}
                                {by_owner.length === 0 && (
                                    <li className="text-sm text-slate-500">
                                        Sin responsables asignados.
                                    </li>
                                )}
                            </ul>
                        </div>
                    </section>

                    <section className={`${card} overflow-hidden !p-0`}>
                        <div className="p-5 pb-3">
                            <h2 className="text-lg font-black">
                                Empresas cliente ({table.count}
                                {table.count !== table.total
                                    ? ` de ${table.total}`
                                    : ''}
                                )
                            </h2>
                        </div>
                        <TableToolbar
                            search={table.search}
                            onSearch={table.setSearch}
                            placeholder="Buscar empresa o contacto…"
                            pageSize={table.pageSize}
                            onPageSize={table.setPageSize}
                        >
                            <TableFilterSelect
                                label="Todas las oficinas"
                                value={office}
                                onChange={setOffice}
                                options={offices.map((name) => ({
                                    value: name,
                                    label: name,
                                }))}
                            />
                            <TableFilterSelect
                                label="Todos los estados"
                                value={status}
                                onChange={setStatus}
                                options={Object.entries(STATUS).map(
                                    ([key, value]) => ({
                                        value: key,
                                        label: value.label,
                                    }),
                                )}
                            />
                        </TableToolbar>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[960px] text-sm">
                                <thead>
                                    <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                        <th className="px-3 py-2">Empresa</th>
                                        <th className="px-3 py-2">
                                            Responsable
                                        </th>
                                        <th className="px-3 py-2">Estado</th>
                                        <th className="px-3 py-2">
                                            Servicio contratado
                                        </th>
                                        <th className="px-3 py-2">
                                            Cuota mensual
                                        </th>
                                        <th className="px-3 py-2">
                                            Vencimiento
                                        </th>
                                        <th className="px-3 py-2">Riesgo</th>
                                        <th className="px-3 py-2">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {table.pageRows.map((row) => (
                                        <tr key={`${row.kind}-${row.id}`}>
                                            <td className="px-3 py-3">
                                                <p className="font-black">
                                                    {row.name}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {row.kind === 'client'
                                                        ? row.location
                                                        : row.stage
                                                          ? stages[row.stage]
                                                          : ''}
                                                </p>
                                            </td>
                                            <td className="px-3 py-3">
                                                {row.owner ?? (
                                                    <span className="text-slate-400">
                                                        Sin asignar
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-3">
                                                <span
                                                    className={`rounded-full px-3 py-1 text-xs font-bold ${STATUS[row.status].style}`}
                                                >
                                                    {STATUS[row.status].label}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                {row.services ??
                                                    (row.kind === 'prospect'
                                                        ? 'Propuesta en revisión'
                                                        : '—')}
                                            </td>
                                            <td className="px-3 py-3">
                                                {row.fee_cents > 0
                                                    ? money(row.fee_cents)
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-3">
                                                {formatDate(row.renews_on)}
                                            </td>
                                            <td className="px-3 py-3">
                                                {row.risk ? (
                                                    <span
                                                        className={`rounded-md px-2.5 py-1 text-xs font-bold ${RISK[row.risk].style}`}
                                                    >
                                                        {RISK[row.risk].label}
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="px-3 py-3">
                                                <div className="flex items-center gap-1.5">
                                                    <button
                                                        type="button"
                                                        className={iconButton}
                                                        title="Ver detalle"
                                                        aria-label={`Ver ${row.name}`}
                                                        onClick={() =>
                                                            setDialog({
                                                                kind: 'detail',
                                                                row,
                                                            })
                                                        }
                                                    >
                                                        <Eye className="size-4" />
                                                    </button>
                                                    {canManage && (
                                                        <>
                                                            <button
                                                                type="button"
                                                                className={
                                                                    iconButton
                                                                }
                                                                title={
                                                                    row.kind ===
                                                                    'client'
                                                                        ? 'Editar contrato'
                                                                        : 'Mover en el pipeline'
                                                                }
                                                                aria-label={`Editar ${row.name}`}
                                                                onClick={() =>
                                                                    setDialog(
                                                                        row.kind ===
                                                                            'client'
                                                                            ? {
                                                                                  kind: 'contract',
                                                                                  companyId:
                                                                                      String(
                                                                                          row.id,
                                                                                      ),
                                                                              }
                                                                            : {
                                                                                  kind: 'pipeline',
                                                                              },
                                                                    )
                                                                }
                                                            >
                                                                <Pencil className="size-4" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className={
                                                                    iconButton
                                                                }
                                                                title="Registrar interacción"
                                                                aria-label={`Registrar interacción con ${row.name}`}
                                                                onClick={() =>
                                                                    setDialog({
                                                                        kind: 'interaction',
                                                                        companyId:
                                                                            row.kind ===
                                                                            'client'
                                                                                ? String(
                                                                                      row.id,
                                                                                  )
                                                                                : undefined,
                                                                        prospectId:
                                                                            row.kind ===
                                                                            'prospect'
                                                                                ? String(
                                                                                      row.id,
                                                                                  )
                                                                                : undefined,
                                                                    })
                                                                }
                                                            >
                                                                <MessageSquarePlus className="size-4" />
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {table.pageRows.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="px-3 py-10 text-center text-slate-500"
                                            >
                                                No hay clientes con esos
                                                criterios.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <TablePagination
                            page={table.page}
                            pages={table.pages}
                            pageSize={table.pageSize}
                            count={table.count}
                            onPage={table.setPage}
                            noun="empresas"
                        />
                    </section>

                    <CrmDialogs
                        dialog={dialog}
                        onClose={() => setDialog(null)}
                        companies={props.companies}
                        staff={props.staff}
                        prospect_options={props.prospect_options}
                        stages={stages}
                        renewals={props.renewals}
                        interactions={props.interactions}
                    />
                </div>
            </main>
        </>
    );
}
