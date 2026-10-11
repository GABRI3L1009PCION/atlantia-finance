import { Head } from '@inertiajs/react';
import {
    Building2,
    CircleDollarSign,
    Gauge,
    Target,
    TrendingUp,
    UserPlus,
    UsersRound,
} from 'lucide-react';
import type { ReactNode } from 'react';

type Props = {
    kpis: {
        mrr_cents: number;
        mrr_delta_cents: number;
        mrr_growth: number | null;
        new_clients: number;
        new_clients_change: number | null;
        offices: number;
        offices_new_quarter: number;
        opportunities: number;
        pipeline_cents: number;
        churn: number;
        churn_year: number;
        capacity: number | null;
        capacity_label: string;
    };
    mrr: { label: string; value_cents: number }[];
    new_clients: { label: string; value: number }[];
    expansion: { name: string; existing: number; new: number }[];
    services: { name: string; value: number }[];
    churn: { label: string; value: number }[];
    offices: {
        name: string;
        companies: number;
        opportunities: number;
        potential_cents: number;
        capacity: number;
    }[];
    prospects: {
        name: string;
        contact: string | null;
        office: string | null;
        value_cents: number;
        probability: number;
        stage: string;
        owner: string | null;
        expected_close_on: string | null;
    }[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white p-5 shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const BRAND = '#8a0b0b';
const STAGES: Record<string, string> = {
    prospect: 'Prospecto',
    contact: 'Contacto',
    proposal: 'Propuesta',
    negotiation: 'Negociación',
};
const money = (cents: number) =>
    `Q${Math.round(cents / 100).toLocaleString('es-GT')}`;
const compact = (cents: number) => {
    const value = cents / 100;

    return value >= 1000
        ? `Q${Math.round(value / 1000)}K`
        : `Q${Math.round(value)}`;
};
const formatDate = (value: string | null) =>
    value
        ? new Date(`${value}T12:00:00`).toLocaleDateString('es-GT', {
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
    good,
}: {
    icon: ReactNode;
    tone: string;
    label: string;
    value: string;
    hint: string;
    good?: boolean | null;
}) {
    return (
        <div className={`${card} flex min-w-0 items-center gap-3 !p-4`}>
            <span
                className={`grid size-14 shrink-0 place-items-center rounded-2xl ${tone}`}
            >
                {icon}
            </span>
            <div className="min-w-0">
                <p className="truncate text-xs font-bold text-[#8a0b0b]">
                    {label}
                </p>
                <p className="text-2xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p
                    className={`truncate text-xs font-semibold ${good === null || good === undefined ? 'text-slate-500' : good ? 'text-emerald-600' : 'text-red-600'}`}
                >
                    {hint}
                </p>
            </div>
        </div>
    );
}

function Title({ children, note }: { children: ReactNode; note?: string }) {
    return (
        <div className="mb-3">
            <h2 className="font-black text-[#061633]">{children}</h2>
            {note && <p className="text-xs text-slate-500">{note}</p>}
        </div>
    );
}

function MrrChart({ points }: { points: Props['mrr'] }) {
    const width = 560;
    const height = 220;
    const left = 44;
    const bottom = 26;
    const max = Math.max(1, ...points.map((point) => point.value_cents));
    const slot = (width - left) / Math.max(points.length, 1);
    const y = (value: number) =>
        10 + (height - bottom - 24) * (1 - value / max);
    const n = points.length;
    const xs = points.map((_, i) => i);
    const meanX = xs.reduce((a, b) => a + b, 0) / Math.max(n, 1);
    const meanY =
        points.reduce((a, p) => a + p.value_cents, 0) / Math.max(n, 1);
    const slope =
        n > 1
            ? xs.reduce(
                  (a, x, i) =>
                      a + (x - meanX) * (points[i].value_cents - meanY),
                  0,
              ) / xs.reduce((a, x) => a + (x - meanX) ** 2, 0)
            : 0;
    const trend = (i: number) => meanY + slope * (i - meanX);

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
            {[0, 0.5, 1].map((tick) => (
                <g key={tick}>
                    <line
                        x1={left}
                        x2={width}
                        y1={y(max * tick)}
                        y2={y(max * tick)}
                        stroke="#eee7e3"
                    />
                    <text
                        x={left - 6}
                        y={y(max * tick)}
                        textAnchor="end"
                        dominantBaseline="central"
                        className="fill-slate-400 text-[10px]"
                    >
                        {compact(max * tick)}
                    </text>
                </g>
            ))}
            {points.map((point, i) => {
                const x = left + slot * i + slot / 2;

                return (
                    <g key={point.label + i}>
                        <rect
                            x={x - slot * 0.3}
                            y={y(point.value_cents)}
                            width={slot * 0.6}
                            height={Math.max(
                                0,
                                height - bottom - y(point.value_cents),
                            )}
                            rx="4"
                            fill={BRAND}
                            opacity={i === n - 1 ? 0.3 : 0.12}
                        />
                        <text
                            x={x}
                            y={height - 8}
                            textAnchor="middle"
                            className="fill-slate-500 text-[10px]"
                        >
                            {point.label}
                        </text>
                    </g>
                );
            })}
            <polyline
                points={points
                    .map(
                        (p, i) =>
                            `${left + slot * i + slot / 2},${y(trend(i))}`,
                    )
                    .join(' ')}
                fill="none"
                stroke={BRAND}
                strokeDasharray="4 4"
                opacity="0.6"
            />
            <polyline
                points={points
                    .map(
                        (p, i) =>
                            `${left + slot * i + slot / 2},${y(p.value_cents)}`,
                    )
                    .join(' ')}
                fill="none"
                stroke={BRAND}
                strokeWidth="2.5"
                strokeLinejoin="round"
            />
            {points.map((point, i) => (
                <circle
                    key={i}
                    cx={left + slot * i + slot / 2}
                    cy={y(point.value_cents)}
                    r="3"
                    fill="#fff"
                    stroke={BRAND}
                    strokeWidth="2"
                />
            ))}
        </svg>
    );
}

function Columns({ points }: { points: Props['new_clients'] }) {
    const max = Math.max(1, ...points.map((point) => point.value));

    return (
        <div className="flex h-52 items-end gap-3 border-b border-slate-100 px-2">
            {points.map((point, i) => (
                <div
                    key={point.label + i}
                    className="flex flex-1 flex-col items-center justify-end"
                >
                    <span className="mb-1 text-xs font-bold">
                        {point.value}
                    </span>
                    <div
                        className="w-full max-w-10 rounded-t-md"
                        style={{
                            height: `${(point.value / max) * 150}px`,
                            background:
                                i === points.length - 1 ? BRAND : '#e8bcbc',
                            minHeight: 4,
                        }}
                    />
                    <span className="mt-1 text-xs text-slate-500">
                        {point.label}
                    </span>
                </div>
            ))}
        </div>
    );
}

function Line({
    points,
    unit,
}: {
    points: { label: string; value: number }[];
    unit: string;
}) {
    const width = 520;
    const height = 180;
    const left = 36;
    const max = Math.max(1, ...points.map((point) => point.value));
    const slot = (width - left) / Math.max(points.length, 1);
    const y = (value: number) => 10 + (height - 40) * (1 - value / max);

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
            {[0, 0.5, 1].map((tick) => (
                <g key={tick}>
                    <line
                        x1={left}
                        x2={width}
                        y1={y(max * tick)}
                        y2={y(max * tick)}
                        stroke="#eee7e3"
                    />
                    <text
                        x={left - 6}
                        y={y(max * tick)}
                        textAnchor="end"
                        dominantBaseline="central"
                        className="fill-slate-400 text-[10px]"
                    >
                        {Math.round(max * tick * 10) / 10}
                        {unit}
                    </text>
                </g>
            ))}
            <polyline
                points={points
                    .map(
                        (p, i) => `${left + slot * i + slot / 2},${y(p.value)}`,
                    )
                    .join(' ')}
                fill="none"
                stroke={BRAND}
                strokeWidth="2.5"
                strokeLinejoin="round"
            />
            {points.map((p, i) => (
                <g key={p.label + i}>
                    <circle
                        cx={left + slot * i + slot / 2}
                        cy={y(p.value)}
                        r="3"
                        fill="#fff"
                        stroke={BRAND}
                        strokeWidth="2"
                    />
                    <text
                        x={left + slot * i + slot / 2}
                        y={height - 8}
                        textAnchor="middle"
                        className="fill-slate-500 text-[10px]"
                    >
                        {p.label}
                    </text>
                </g>
            ))}
        </svg>
    );
}

export default function DirectorGrowth({
    kpis,
    mrr,
    new_clients,
    expansion,
    services,
    churn,
    offices,
    prospects,
}: Props) {
    const maxService = Math.max(1, ...services.map((service) => service.value));
    const totalServices = services.reduce(
        (sum, service) => sum + service.value,
        0,
    );
    const maxOffice = Math.max(
        1,
        ...expansion.map((office) => office.existing + office.new),
    );

    return (
        <>
            <Head title="Crecimiento" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                            <TrendingUp className="size-7" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">
                                Crecimiento
                            </h1>
                            <p className="text-sm text-slate-600">
                                Crecimiento de la firma según sus clientes,
                                contratos y oportunidades.
                            </p>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        <Kpi
                            icon={<CircleDollarSign className="size-7" />}
                            tone="bg-[#f6e6e4] text-[#8a0b0b]"
                            label="Crecimiento mensual (MRR)"
                            value={
                                kpis.mrr_growth === null
                                    ? money(kpis.mrr_cents)
                                    : `${kpis.mrr_growth >= 0 ? '+' : ''}${kpis.mrr_growth}%`
                            }
                            hint={`${kpis.mrr_delta_cents >= 0 ? '+' : ''}${money(kpis.mrr_delta_cents)} vs. mes anterior`}
                            good={kpis.mrr_delta_cents >= 0}
                        />
                        <Kpi
                            icon={<UserPlus className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Nuevas empresas cliente"
                            value={String(kpis.new_clients)}
                            hint={
                                kpis.new_clients_change === null
                                    ? 'Este mes'
                                    : `${kpis.new_clients_change >= 0 ? '+' : ''}${kpis.new_clients_change}% vs. mes anterior`
                            }
                            good={
                                kpis.new_clients_change === null
                                    ? null
                                    : kpis.new_clients_change >= 0
                            }
                        />
                        <Kpi
                            icon={<Building2 className="size-7" />}
                            tone="bg-blue-50 text-blue-600"
                            label="Oficinas"
                            value={`${kpis.offices}`}
                            hint={`+${kpis.offices_new_quarter} nuevas este trimestre`}
                            good={kpis.offices_new_quarter > 0 ? true : null}
                        />
                        <Kpi
                            icon={<Target className="size-7" />}
                            tone="bg-red-50 text-red-500"
                            label="Oportunidades abiertas"
                            value={String(kpis.opportunities)}
                            hint={`${money(kpis.pipeline_cents)} en pipeline`}
                        />
                        <Kpi
                            icon={<UsersRound className="size-7" />}
                            tone="bg-green-50 text-green-600"
                            label="Churn de clientes"
                            value={`${kpis.churn_year}%`}
                            hint="Contratos cancelados (12 meses)"
                            good={
                                kpis.churn_year === 0
                                    ? true
                                    : kpis.churn_year < 5
                            }
                        />
                        <Kpi
                            icon={<Gauge className="size-7" />}
                            tone="bg-orange-50 text-orange-500"
                            label="Capacidad disponible"
                            value={
                                kpis.capacity === null
                                    ? '∞'
                                    : `${kpis.capacity}%`
                            }
                            hint={kpis.capacity_label}
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.4fr_1fr_1fr]">
                        <div className={card}>
                            <Title note="Cuotas mensuales de contratos vigentes; la línea punteada es la tendencia.">
                                Tendencia de crecimiento de ingresos (MRR)
                            </Title>
                            <MrrChart points={mrr} />
                        </div>
                        <div className={card}>
                            <Title note="Empresas dadas de alta por mes.">
                                Nuevas empresas cliente
                            </Title>
                            <Columns points={new_clients} />
                        </div>
                        <div className={card}>
                            <Title note="Empresas existentes y nuevas este año.">
                                Expansión por oficina
                            </Title>
                            <ul className="space-y-3">
                                {expansion.map((office) => (
                                    <li key={office.name}>
                                        <div className="mb-1 flex justify-between text-sm">
                                            <span className="truncate font-bold">
                                                {office.name}
                                            </span>
                                            <span className="text-slate-500">
                                                {office.existing + office.new}
                                            </span>
                                        </div>
                                        <div
                                            className="flex h-4 overflow-hidden rounded bg-slate-100"
                                            style={{
                                                width: `${((office.existing + office.new) / maxOffice) * 100}%`,
                                                minWidth: '12%',
                                            }}
                                        >
                                            <div
                                                className="h-full bg-[#e8bcbc]"
                                                style={{
                                                    width: `${(office.existing / Math.max(1, office.existing + office.new)) * 100}%`,
                                                }}
                                            />
                                            <div
                                                className="h-full bg-[#8a0b0b]"
                                                style={{
                                                    width: `${(office.new / Math.max(1, office.existing + office.new)) * 100}%`,
                                                }}
                                            />
                                        </div>
                                    </li>
                                ))}
                                <li className="flex gap-4 text-xs text-slate-500">
                                    <span className="flex items-center gap-1.5">
                                        <i className="size-2.5 rounded-full bg-[#e8bcbc]" />{' '}
                                        Existentes
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        <i className="size-2.5 rounded-full bg-[#8a0b0b]" />{' '}
                                        Nuevas
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1.3fr_1fr]">
                        <div className={card}>
                            <Title note="Servicios incluidos en los contratos vigentes.">
                                Demanda de servicios
                            </Title>
                            <ul className="space-y-3">
                                {services.map((service) => (
                                    <li
                                        key={service.name}
                                        className="grid grid-cols-[10rem_1fr_auto_auto] items-center gap-3 text-sm"
                                    >
                                        <span className="truncate">
                                            {service.name}
                                        </span>
                                        <div className="h-3 overflow-hidden rounded bg-slate-100">
                                            <div
                                                className="h-full rounded bg-[#8a0b0b]"
                                                style={{
                                                    width: `${(service.value / maxService) * 100}%`,
                                                }}
                                            />
                                        </div>
                                        <b>{service.value}</b>
                                        <span className="w-10 text-right text-xs text-slate-500">
                                            {totalServices
                                                ? Math.round(
                                                      (service.value /
                                                          totalServices) *
                                                          100,
                                                  )
                                                : 0}
                                            %
                                        </span>
                                    </li>
                                ))}
                                {services.length === 0 && (
                                    <li className="text-sm text-slate-500">
                                        Aún no hay contratos registrados.
                                    </li>
                                )}
                            </ul>
                        </div>
                        <div className={card}>
                            <Title note="Contratos cancelados cada mes sobre los vigentes al inicio.">
                                Tasa de pérdida de clientes (churn)
                            </Title>
                            <Line points={churn} unit="%" />
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[1fr_1.4fr]">
                        <div className={`${card} overflow-x-auto`}>
                            <Title note="Oportunidades en el pipeline y espacio del equipo.">
                                Oficinas con mayor potencial
                            </Title>
                            <table className="w-full min-w-[420px] text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-500">
                                        <th className="py-1">Oficina</th>
                                        <th>Potencial</th>
                                        <th className="text-center">
                                            Oportunidades
                                        </th>
                                        <th className="text-center">
                                            Capacidad disponible
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {offices.map((office) => (
                                        <tr key={office.name}>
                                            <td className="py-2.5 font-bold">
                                                {office.name}
                                            </td>
                                            <td>
                                                {money(office.potential_cents)}
                                            </td>
                                            <td className="text-center">
                                                {office.opportunities}
                                            </td>
                                            <td className="text-center font-bold">
                                                {office.capacity}%
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className={`${card} overflow-x-auto`}>
                            <Title note="Probabilidad estimada según la etapa del pipeline (convención de planeación, no una tasa medida).">
                                Prospectos y oportunidades de expansión
                            </Title>
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-slate-500">
                                        <th className="py-1">
                                            Empresa / Contacto
                                        </th>
                                        <th>Oficina</th>
                                        <th>Valor estimado</th>
                                        <th>Probabilidad</th>
                                        <th>Etapa</th>
                                        <th>Responsable</th>
                                        <th>Cierre esperado</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {prospects.map((prospect) => (
                                        <tr key={prospect.name}>
                                            <td className="py-2.5">
                                                <p className="font-bold">
                                                    {prospect.name}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {prospect.contact ?? '—'}
                                                </p>
                                            </td>
                                            <td>{prospect.office ?? '—'}</td>
                                            <td className="font-bold">
                                                {money(prospect.value_cents)}
                                            </td>
                                            <td>
                                                <div className="flex items-center gap-2">
                                                    <b className="w-9 text-xs">
                                                        {prospect.probability}%
                                                    </b>
                                                    <div className="h-2 w-16 overflow-hidden rounded-full bg-slate-100">
                                                        <div
                                                            className="h-full rounded-full"
                                                            style={{
                                                                width: `${prospect.probability}%`,
                                                                background:
                                                                    prospect.probability >=
                                                                    60
                                                                        ? '#16a34a'
                                                                        : prospect.probability >=
                                                                            40
                                                                          ? '#f0a93b'
                                                                          : '#ee6c3a',
                                                            }}
                                                        />
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                {STAGES[prospect.stage] ??
                                                    prospect.stage}
                                            </td>
                                            <td>{prospect.owner ?? '—'}</td>
                                            <td>
                                                {formatDate(
                                                    prospect.expected_close_on,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {prospects.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="py-8 text-center text-slate-500"
                                            >
                                                No hay oportunidades abiertas.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
