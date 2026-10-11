import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CalendarClock,
    CircleDollarSign,
    HandCoins,
    Plus,
    RefreshCcw,
    Search,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

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

function normalize(value: string): string {
    return value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

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
                className={`grid size-14 shrink-0 place-items-center rounded-2xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-bold text-[#8a0b0b]">
                    {label}
                </p>
                <p className="text-3xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p
                    className={`truncate text-xs font-semibold ${bad ? 'text-red-600' : 'text-slate-500'}`}
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

function ManagePanel({
    companies,
    staff,
    prospectOptions,
}: Pick<Props, 'companies' | 'staff'> & {
    prospectOptions: Props['prospect_options'];
}) {
    const prospect = useForm({
        name: '',
        contact_name: '',
        contact_email: '',
        stage: 'prospect',
        estimated_value: '',
        expected_close_on: '',
    });
    const contract = useForm({
        company_id: '',
        services: '',
        monthly_fee: '',
        starts_on: '',
        renews_on: '',
        status: 'active',
        owner_id: '',
    });
    const interaction = useForm({
        company_id: '',
        crm_prospect_id: '',
        kind: 'meeting',
        summary: '',
        next_action: '',
        next_action_on: '',
    });

    const submit =
        (
            form: {
                post: (url: string, options: object) => void;
                reset: () => void;
            },
            url: string,
        ) =>
        (event: FormEvent) => {
            event.preventDefault();
            form.post(url, {
                preserveScroll: true,
                onSuccess: () => form.reset(),
            });
        };

    return (
        <div className="grid gap-4 xl:grid-cols-3">
            <form
                onSubmit={submit(prospect, '/office/crm/prospects')}
                className={`${card} space-y-3`}
            >
                <h3 className="font-black">Registrar prospecto</h3>
                <input
                    className={field}
                    placeholder="Empresa o persona"
                    value={prospect.data.name}
                    onChange={(e) => prospect.setData('name', e.target.value)}
                    required
                />
                <input
                    className={field}
                    placeholder="Contacto"
                    value={prospect.data.contact_name}
                    onChange={(e) =>
                        prospect.setData('contact_name', e.target.value)
                    }
                />
                <input
                    className={field}
                    type="email"
                    placeholder="Correo"
                    value={prospect.data.contact_email}
                    onChange={(e) =>
                        prospect.setData('contact_email', e.target.value)
                    }
                />
                <div className="grid grid-cols-2 gap-3">
                    <select
                        className={field}
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
                    <input
                        className={field}
                        type="number"
                        min="0"
                        placeholder="Valor estimado (Q)"
                        value={prospect.data.estimated_value}
                        onChange={(e) =>
                            prospect.setData('estimated_value', e.target.value)
                        }
                    />
                </div>
                <input
                    className={field}
                    type="date"
                    value={prospect.data.expected_close_on}
                    onChange={(e) =>
                        prospect.setData('expected_close_on', e.target.value)
                    }
                />
                <button className={button} disabled={prospect.processing}>
                    <Plus className="size-4" /> Guardar prospecto
                </button>
            </form>

            <form
                onSubmit={submit(contract, '/office/crm/contracts')}
                className={`${card} space-y-3`}
            >
                <h3 className="font-black">Contrato de un cliente</h3>
                <select
                    className={field}
                    value={contract.data.company_id}
                    onChange={(e) =>
                        contract.setData('company_id', e.target.value)
                    }
                    required
                >
                    <option value="">Empresa cliente</option>
                    {companies.map((company) => (
                        <option key={company.id} value={company.id}>
                            {company.name}
                        </option>
                    ))}
                </select>
                <input
                    className={field}
                    placeholder="Servicios (ej. Contabilidad + Impuestos)"
                    value={contract.data.services}
                    onChange={(e) =>
                        contract.setData('services', e.target.value)
                    }
                    required
                />
                <div className="grid grid-cols-2 gap-3">
                    <input
                        className={field}
                        type="number"
                        min="0"
                        step="0.01"
                        placeholder="Cuota mensual (Q)"
                        value={contract.data.monthly_fee}
                        onChange={(e) =>
                            contract.setData('monthly_fee', e.target.value)
                        }
                        required
                    />
                    <select
                        className={field}
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
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <label className="text-xs text-slate-500">
                        Inicio
                        <input
                            className={field}
                            type="date"
                            value={contract.data.starts_on}
                            onChange={(e) =>
                                contract.setData('starts_on', e.target.value)
                            }
                            required
                        />
                    </label>
                    <label className="text-xs text-slate-500">
                        Renueva
                        <input
                            className={field}
                            type="date"
                            value={contract.data.renews_on}
                            onChange={(e) =>
                                contract.setData('renews_on', e.target.value)
                            }
                        />
                    </label>
                </div>
                <select
                    className={field}
                    value={contract.data.owner_id}
                    onChange={(e) =>
                        contract.setData('owner_id', e.target.value)
                    }
                >
                    <option value="">Responsable (opcional)</option>
                    {staff.map((person) => (
                        <option key={person.id} value={person.id}>
                            {person.name}
                        </option>
                    ))}
                </select>
                <button className={button} disabled={contract.processing}>
                    <HandCoins className="size-4" /> Guardar contrato
                </button>
            </form>

            <form
                onSubmit={submit(interaction, '/office/crm/interactions')}
                className={`${card} space-y-3`}
            >
                <h3 className="font-black">Registrar interacción</h3>
                <select
                    className={field}
                    value={interaction.data.company_id}
                    onChange={(e) =>
                        interaction.setData('company_id', e.target.value)
                    }
                >
                    <option value="">Cliente (opcional)</option>
                    {companies.map((company) => (
                        <option key={company.id} value={company.id}>
                            {company.name}
                        </option>
                    ))}
                </select>
                <select
                    className={field}
                    value={interaction.data.crm_prospect_id}
                    onChange={(e) =>
                        interaction.setData('crm_prospect_id', e.target.value)
                    }
                >
                    <option value="">Prospecto (opcional)</option>
                    {prospectOptions.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
                <select
                    className={field}
                    value={interaction.data.kind}
                    onChange={(e) =>
                        interaction.setData('kind', e.target.value)
                    }
                >
                    {Object.entries(KIND).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
                <input
                    className={field}
                    placeholder="Resumen"
                    value={interaction.data.summary}
                    onChange={(e) =>
                        interaction.setData('summary', e.target.value)
                    }
                    required
                />
                <input
                    className={field}
                    placeholder="Próxima acción"
                    value={interaction.data.next_action}
                    onChange={(e) =>
                        interaction.setData('next_action', e.target.value)
                    }
                />
                <input
                    className={field}
                    type="date"
                    value={interaction.data.next_action_on}
                    onChange={(e) =>
                        interaction.setData('next_action_on', e.target.value)
                    }
                />
                <button className={button} disabled={interaction.processing}>
                    <Plus className="size-4" /> Guardar interacción
                </button>
            </form>
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
        alerts,
        renewals,
        interactions,
        stages,
        canManage,
    } = props;
    const [search, setSearch] = useState('');
    const [office, setOffice] = useState('');
    const [status, setStatus] = useState('');
    const visible = useMemo(() => {
        const term = normalize(search.trim());

        return rows.filter(
            (row) =>
                (term === '' ||
                    normalize(
                        `${row.name} ${row.location ?? ''} ${row.owner ?? ''}`,
                    ).includes(term)) &&
                (office === '' || row.office === office) &&
                (status === '' || row.status === status),
        );
    }, [rows, search, office, status]);
    const maxOwner = Math.max(1, ...by_owner.map((owner) => owner.value));

    return (
        <>
            <Head title="Clientes (CRM)" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                            <Users className="size-7" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">
                                Clientes (CRM)
                            </h1>
                            <p className="text-sm text-slate-600">
                                Relaciones comerciales y seguimiento del ciclo
                                de vida de cada cliente.
                            </p>
                        </div>
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

                    <section className={`${card} overflow-x-auto`}>
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-lg font-black">
                                Empresas cliente ({visible.length})
                            </h2>
                            <div className="flex flex-wrap gap-2">
                                <label className="relative">
                                    <Search className="absolute top-3 left-3 size-4 text-slate-400" />
                                    <input
                                        className={`${field} w-60 pl-9`}
                                        placeholder="Buscar empresa o contacto..."
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                    />
                                </label>
                                <select
                                    className={`${field} w-auto`}
                                    value={office}
                                    onChange={(e) => setOffice(e.target.value)}
                                >
                                    <option value="">Todas las oficinas</option>
                                    {offices.map((name) => (
                                        <option key={name}>{name}</option>
                                    ))}
                                </select>
                                <select
                                    className={`${field} w-auto`}
                                    value={status}
                                    onChange={(e) => setStatus(e.target.value)}
                                >
                                    <option value="">Todos los estados</option>
                                    {Object.entries(STATUS).map(
                                        ([key, value]) => (
                                            <option key={key} value={key}>
                                                {value.label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </div>
                        </div>
                        <table className="w-full min-w-[860px] text-sm">
                            <thead>
                                <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                    <th className="px-3 py-2">Empresa</th>
                                    <th className="px-3 py-2">Responsable</th>
                                    <th className="px-3 py-2">Estado</th>
                                    <th className="px-3 py-2">
                                        Servicio contratado
                                    </th>
                                    <th className="px-3 py-2">Cuota mensual</th>
                                    <th className="px-3 py-2">Vencimiento</th>
                                    <th className="px-3 py-2">Riesgo</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {visible.map((row) => (
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
                                    </tr>
                                ))}
                                {visible.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-3 py-10 text-center text-slate-500"
                                        >
                                            No hay clientes con esos criterios.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-3">
                        <div className={card}>
                            <h2 className="mb-3 flex items-center gap-2 font-black">
                                <AlertTriangle className="size-5 text-red-500" />{' '}
                                Alertas y pendientes{' '}
                                <span className="rounded-full bg-red-50 px-2 text-xs text-red-600">
                                    {alerts.length}
                                </span>
                            </h2>
                            <ul className="space-y-2.5">
                                {alerts.map((alert) => (
                                    <li
                                        key={alert.title}
                                        className="flex items-center gap-3 rounded-xl border border-slate-100 p-3"
                                    >
                                        <span
                                            className={`grid size-9 shrink-0 place-items-center rounded-lg ${alert.tone === 'red' ? 'bg-red-50 text-red-600' : alert.tone === 'amber' ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600'}`}
                                        >
                                            <AlertTriangle className="size-5" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-black">
                                                {alert.title}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {alert.detail}
                                            </p>
                                        </div>
                                        <b className="rounded-full bg-slate-50 px-2.5 py-1 text-sm">
                                            {alert.count}
                                        </b>
                                    </li>
                                ))}
                                {alerts.length === 0 && (
                                    <li className="text-sm text-slate-500">
                                        Sin alertas comerciales.
                                    </li>
                                )}
                            </ul>
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 flex items-center gap-2 font-black">
                                <CalendarClock className="size-5 text-[#8a0b0b]" />{' '}
                                Próximas renovaciones
                            </h2>
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
                                        <tr
                                            key={`${item.company}-${item.renews_on}`}
                                        >
                                            <td className="py-2 font-bold">
                                                {item.company}
                                            </td>
                                            <td className="text-slate-600">
                                                {item.services}
                                            </td>
                                            <td className="font-bold text-[#8a0b0b]">
                                                {formatDate(item.renews_on)}
                                            </td>
                                        </tr>
                                    ))}
                                    {renewals.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={3}
                                                className="py-6 text-center text-slate-500"
                                            >
                                                Sin renovaciones próximas.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <div className={card}>
                            <h2 className="mb-3 font-black">
                                Interacciones recientes
                            </h2>
                            <ul className="space-y-3">
                                {interactions.map((item, index) => (
                                    <li
                                        key={index}
                                        className="flex items-start gap-3 text-sm"
                                    >
                                        <span className="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full bg-[#f6e6e4] text-xs font-black text-[#8a0b0b]">
                                            {item.user.charAt(0)}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="font-black">
                                                {item.user}
                                                {item.about
                                                    ? ` · ${item.about}`
                                                    : ''}
                                            </p>
                                            <p className="text-xs text-slate-600">
                                                {KIND[
                                                    item.kind as keyof typeof KIND
                                                ] ?? 'Nota'}
                                                : {item.summary}
                                            </p>
                                        </div>
                                        <span className="shrink-0 text-xs text-slate-400">
                                            {formatDate(item.at)}
                                        </span>
                                    </li>
                                ))}
                                {interactions.length === 0 && (
                                    <li className="text-sm text-slate-500">
                                        Aún no hay interacciones registradas.
                                    </li>
                                )}
                            </ul>
                        </div>
                    </section>

                    {canManage && (
                        <section>
                            <h2 className="mb-3 text-lg font-black">
                                Gestión comercial
                            </h2>
                            <ManagePanel
                                companies={props.companies}
                                staff={props.staff}
                                prospectOptions={props.prospect_options}
                            />
                            {props.prospect_options.length > 0 && (
                                <div className={`${card} mt-4`}>
                                    <h3 className="mb-3 font-black">
                                        Mover prospectos en el pipeline
                                    </h3>
                                    <ul className="divide-y divide-slate-100 text-sm">
                                        {props.prospect_options.map((item) => (
                                            <li
                                                key={item.id}
                                                className="flex items-center justify-between gap-3 py-2"
                                            >
                                                <span className="font-bold">
                                                    {item.name}
                                                </span>
                                                <select
                                                    className="rounded-lg border border-[#e4dbd6] px-2 py-1 text-xs"
                                                    value={item.stage}
                                                    onChange={(e) =>
                                                        router.patch(
                                                            `/office/crm/prospects/${item.id}`,
                                                            {
                                                                stage: e.target
                                                                    .value,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {Object.entries(stages).map(
                                                        ([key, label]) => (
                                                            <option
                                                                key={key}
                                                                value={key}
                                                            >
                                                                {label}
                                                            </option>
                                                        ),
                                                    )}
                                                    <option value="lost">
                                                        Perdido
                                                    </option>
                                                </select>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </section>
                    )}
                </div>
            </main>
        </>
    );
}
