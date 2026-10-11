import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    CheckCircle2,
    ClipboardX,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { ExecutiveDashboardData } from '@/components/executive-dashboard';

type Company = ExecutiveDashboardData['companies'][number];

export type DirectorFirm = {
    trend: { label: string; value: number }[];
    growth: { label: string; total: number }[];
    new_companies: number;
    capacity: { limit: number | null; active: number; available: number | null };
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const BRAND = '#8a0b0b';

const STATUS_GROUPS = [
    { key: 'ok', label: 'Al día', color: '#12a594' },
    { key: 'progress', label: 'En proceso', color: '#1f9d8b' },
    { key: 'review', label: 'En revisión', color: '#f0a93b' },
    { key: 'late', label: 'Con retraso', color: '#ee6c3a' },
    { key: 'blocked', label: 'Bloqueadas', color: '#d6252e' },
] as const;

type GroupKey = (typeof STATUS_GROUPS)[number]['key'];

function groupOf(company: Company): GroupKey {
    if (company.status === 'blocked') {
        return 'blocked';
    }

    if (company.is_overdue || (company.overdue_tasks ?? 0) > 0) {
        return 'late';
    }

    if (
        ['ready_for_review', 'in_review', 'changes_requested'].includes(
            company.status,
        )
    ) {
        return 'review';
    }

    if (['approved', 'closed'].includes(company.status)) {
        return 'ok';
    }

    return 'progress';
}

function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12
        ? 'Buenos días'
        : hour < 19
          ? 'Buenas tardes'
          : 'Buenas noches';
}

function pct(part: number, whole: number): number {
    return whole > 0 ? Math.round((part / whole) * 100) : 0;
}

function Ring({
    value,
    size = 56,
    color = '#12a594',
}: {
    value: number;
    size?: number;
    color?: string;
}) {
    const radius = (size - 8) / 2;
    const circumference = 2 * Math.PI * radius;

    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke="#eef1f3"
                strokeWidth="6"
            />
            <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke={color}
                strokeWidth="6"
                strokeLinecap="round"
                strokeDasharray={`${(Math.min(100, value) / 100) * circumference} ${circumference}`}
                transform={`rotate(-90 ${size / 2} ${size / 2})`}
            />
            <text
                x="50%"
                y="50%"
                dominantBaseline="central"
                textAnchor="middle"
                className="fill-[#061633] text-[11px] font-black"
            >
                {value}%
            </text>
        </svg>
    );
}

function Bar({ value, tone }: { value: number; tone?: 'ok' | 'warn' | 'bad' }) {
    const color =
        tone === 'bad' ? '#d6252e' : tone === 'warn' ? '#f0a93b' : '#12a594';

    return (
        <div className="h-2 min-w-16 flex-1 overflow-hidden rounded-full bg-slate-100">
            <div
                className="h-full rounded-full transition-[width] duration-700"
                style={{
                    width: `${Math.min(100, Math.max(0, value))}%`,
                    background: color,
                }}
            />
        </div>
    );
}

function CardTitle({ children, aside }: { children: ReactNode; aside?: ReactNode }) {
    return (
        <div className="mb-3 flex items-center justify-between gap-2">
            <h2 className="text-base font-black text-[#061633]">{children}</h2>
            {aside}
        </div>
    );
}

function Kpi({
    icon,
    label,
    value,
    hint,
    tone = 'slate',
}: {
    icon: ReactNode;
    label: string;
    value: ReactNode;
    hint: string;
    tone?: 'slate' | 'good' | 'bad';
}) {
    return (
        <div className={`${card} flex min-w-0 items-center gap-3 p-4`}>
            {icon}
            <div className="min-w-0">
                <p className="truncate text-xs font-bold text-[#8a0b0b]">
                    {label}
                </p>
                <p className="text-3xl leading-tight font-black text-[#061633]">
                    {value}
                </p>
                <p
                    className={`truncate text-xs font-semibold ${tone === 'good' ? 'text-emerald-700' : tone === 'bad' ? 'text-red-600' : 'text-slate-500'}`}
                >
                    {hint}
                </p>
            </div>
        </div>
    );
}

function IconBubble({ children, tone }: { children: ReactNode; tone: string }) {
    return (
        <span
            className={`grid size-14 shrink-0 place-items-center rounded-2xl ${tone}`}
        >
            {children}
        </span>
    );
}

function TrendChart({ points }: { points: DirectorFirm['trend'] }) {
    const width = 520;
    const height = 190;
    const left = 34;
    const bottom = 26;
    const slot = (width - left) / Math.max(points.length, 1);
    const y = (value: number) =>
        8 + (height - bottom - 16) * (1 - Math.min(100, value) / 100);
    const line = points
        .map((point, index) => `${left + slot * index + slot / 2},${y(point.value)}`)
        .join(' ');

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
            {[0, 25, 50, 75, 100].map((tick) => (
                <g key={tick}>
                    <line
                        x1={left}
                        x2={width}
                        y1={y(tick)}
                        y2={y(tick)}
                        stroke="#eee7e3"
                    />
                    <text
                        x={left - 6}
                        y={y(tick)}
                        textAnchor="end"
                        dominantBaseline="central"
                        className="fill-slate-400 text-[10px]"
                    >
                        {tick}%
                    </text>
                </g>
            ))}
            {points.map((point, index) => {
                const x = left + slot * index + slot / 2;
                const last = index === points.length - 1;

                return (
                    <g key={point.label + index}>
                        <rect
                            x={x - slot * 0.28}
                            y={y(point.value)}
                            width={slot * 0.56}
                            height={Math.max(0, height - bottom - y(point.value))}
                            rx="6"
                            fill={BRAND}
                            opacity={last ? 0.28 : 0.12}
                        />
                        <text
                            x={x}
                            y={height - 8}
                            textAnchor="middle"
                            className={`text-[10px] ${last ? 'fill-[#061633] font-black' : 'fill-slate-500'}`}
                        >
                            {point.label}
                        </text>
                        <text
                            x={x}
                            y={y(point.value) - 8}
                            textAnchor="middle"
                            className="fill-[#6b1d1d] text-[10px] font-bold"
                        >
                            {point.value}%
                        </text>
                    </g>
                );
            })}
            <polyline
                points={line}
                fill="none"
                stroke={BRAND}
                strokeWidth="2.5"
                strokeLinejoin="round"
            />
            {points.map((point, index) => (
                <circle
                    key={point.label + index}
                    cx={left + slot * index + slot / 2}
                    cy={y(point.value)}
                    r="3.5"
                    fill="#fff"
                    stroke={BRAND}
                    strokeWidth="2"
                />
            ))}
        </svg>
    );
}

function Donut({
    counts,
    total,
}: {
    counts: Record<GroupKey, number>;
    total: number;
}) {
    const radius = 54;
    const circumference = 2 * Math.PI * radius;
    const lengths = STATUS_GROUPS.map((group) =>
        total > 0 ? (counts[group.key] / total) * circumference : 0,
    );

    return (
        <svg viewBox="0 0 140 140" className="size-36 shrink-0">
            <circle
                cx="70"
                cy="70"
                r={radius}
                fill="none"
                stroke="#eef1f3"
                strokeWidth="16"
            />
            {STATUS_GROUPS.map((group, index) => (
                <circle
                    key={group.key}
                    cx="70"
                    cy="70"
                    r={radius}
                    fill="none"
                    stroke={group.color}
                    strokeWidth="16"
                    strokeDasharray={`${lengths[index]} ${circumference - lengths[index]}`}
                    strokeDashoffset={-lengths
                        .slice(0, index)
                        .reduce((sum, length) => sum + length, 0)}
                    transform="rotate(-90 70 70)"
                />
            ))}
            <text
                x="70"
                y="66"
                textAnchor="middle"
                className="fill-[#061633] text-[26px] font-black"
            >
                {total}
            </text>
            <text
                x="70"
                y="84"
                textAnchor="middle"
                className="fill-slate-500 text-[10px]"
            >
                en total
            </text>
        </svg>
    );
}

function GrowthChart({ points }: { points: DirectorFirm['growth'] }) {
    const width = 320;
    const height = 120;
    const values = points.map((point) => point.total);
    const min = Math.min(...values, 0);
    const max = Math.max(...values, 1);
    const x = (index: number) =>
        16 + ((width - 32) / Math.max(points.length - 1, 1)) * index;
    const y = (value: number) =>
        10 + (height - 34) * (1 - (value - min) / Math.max(max - min, 1));
    const line = points.map((point, index) => `${x(index)},${y(point.total)}`);

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full">
            <polygon
                points={`${x(0)},${height - 22} ${line.join(' ')} ${x(points.length - 1)},${height - 22}`}
                fill={BRAND}
                opacity="0.08"
            />
            <polyline
                points={line.join(' ')}
                fill="none"
                stroke={BRAND}
                strokeWidth="2.5"
                strokeLinejoin="round"
            />
            {points.map((point, index) => (
                <g key={point.label + index}>
                    <circle
                        cx={x(index)}
                        cy={y(point.total)}
                        r="3.5"
                        fill="#fff"
                        stroke={BRAND}
                        strokeWidth="2"
                    />
                    <text
                        x={x(index)}
                        y={y(point.total) - 8}
                        textAnchor="middle"
                        className="fill-[#6b1d1d] text-[10px] font-bold"
                    >
                        {point.total}
                    </text>
                    <text
                        x={x(index)}
                        y={height - 6}
                        textAnchor="middle"
                        className="fill-slate-500 text-[10px]"
                    >
                        {point.label}
                    </text>
                </g>
            ))}
        </svg>
    );
}

function Gauge({ value }: { value: number }) {
    const radius = 52;
    const half = Math.PI * radius;

    return (
        <svg viewBox="0 0 130 78" className="w-40">
            <path
                d="M13 65 A52 52 0 0 1 117 65"
                fill="none"
                stroke="#eef1f3"
                strokeWidth="14"
                strokeLinecap="round"
            />
            <path
                d="M13 65 A52 52 0 0 1 117 65"
                fill="none"
                stroke="#12a594"
                strokeWidth="14"
                strokeLinecap="round"
                strokeDasharray={`${(Math.min(100, value) / 100) * half} ${half}`}
            />
            <text
                x="65"
                y="60"
                textAnchor="middle"
                className="fill-[#061633] text-[20px] font-black"
            >
                {value}%
            </text>
        </svg>
    );
}

const loadTone = (value: number): 'ok' | 'warn' | 'bad' =>
    value >= 90 ? 'bad' : value >= 75 ? 'warn' : 'ok';

export function DirectorOverview({
    dashboard,
    firm,
    scopeLabel,
    periodName,
    filters,
}: {
    dashboard: ExecutiveDashboardData;
    firm: DirectorFirm;
    scopeLabel: string;
    periodName?: string;
    filters: ReactNode;
}) {
    const user = usePage().props.auth.user as { name: string };
    const firstName = user.name.split(' ')[0];
    const companies = dashboard.companies;
    const total = companies.length;
    const counts: Record<GroupKey, number> = {
        ok: 0,
        progress: 0,
        review: 0,
        late: 0,
        blocked: 0,
    };

    companies.forEach((company) => {
        counts[groupOf(company)] += 1;
    });

    const averageProgress = total
        ? Math.round(
              companies.reduce((sum, company) => sum + company.progress, 0) /
                  total,
          )
        : 0;
    const atRisk = companies
        .filter((company) => ['late', 'blocked'].includes(groupOf(company)))
        .sort(
            (a, b) =>
                (b.overdue_tasks ?? 0) - (a.overdue_tasks ?? 0) ||
                b.pending_tasks - a.pending_tasks,
        );
    const team = [...dashboard.workload].sort(
        (a, b) =>
            (b.utilization_percentage ?? 0) - (a.utilization_percentage ?? 0),
    );
    const overloaded = team.filter(
        (person) => (person.utilization_percentage ?? 0) >= 90,
    ).length;
    const offices = [...dashboard.offices].sort(
        (a, b) => b.companies - a.companies,
    );
    const upcoming = dashboard.tasks
        .filter(
            (task) =>
                task.due_at &&
                !['completed', 'approved', 'cancelled'].includes(task.status),
        )
        .slice(0, 4);
    const fullTeam = companies.filter(
        (company) => company.accountant && company.assistant,
    ).length;
    const capacity = firm.capacity;
    const capacityUse = capacity.limit
        ? pct(capacity.active, capacity.limit)
        : 0;
    const growthFirst = firm.growth[0]?.total ?? 0;
    const growthLast = firm.growth[firm.growth.length - 1]?.total ?? 0;
    const growthPct = growthFirst > 0 ? pct(growthLast - growthFirst, growthFirst) : 0;

    return (
        <div className="space-y-4">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="grid size-12 place-items-center rounded-full bg-amber-50 text-amber-500">
                        <CalendarDays className="size-6" />
                    </span>
                    <div>
                        <h1 className="text-3xl font-black tracking-tight text-[#061633]">
                            {greeting()}, {firstName}
                        </h1>
                        <p className="text-sm text-slate-600">
                            Aquí tienes el panorama general de tu firma contable
                            · {scopeLabel}
                            {periodName ? ` · ${periodName}` : ''}
                        </p>
                    </div>
                </div>
                {filters}
            </header>

            <section
                id="indicadores"
                className="grid scroll-mt-24 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6"
            >
                <Kpi
                    icon={
                        <IconBubble tone="bg-[#f6e6e4] text-[#8a0b0b]">
                            <Building2 className="size-7" />
                        </IconBubble>
                    }
                    label="Empresas cliente"
                    value={dashboard.summary.active_companies}
                    hint={
                        firm.new_companies > 0
                            ? `↑ +${firm.new_companies} en 6 meses`
                            : 'Sin altas en 6 meses'
                    }
                    tone={firm.new_companies > 0 ? 'good' : 'slate'}
                />
                <Kpi
                    icon={<Ring value={averageProgress} size={64} />}
                    label="Cumplimiento general"
                    value={`${averageProgress}%`}
                    hint="Avance promedio del período"
                />
                <Kpi
                    icon={
                        <IconBubble tone="bg-blue-50 text-blue-600">
                            <CheckCircle2 className="size-7" />
                        </IconBubble>
                    }
                    label="Cierres del mes"
                    value={`${pct(dashboard.summary.completed_closings, dashboard.summary.active_companies)}%`}
                    hint={`${dashboard.summary.completed_closings} de ${dashboard.summary.active_companies} empresas`}
                />
                <Kpi
                    icon={
                        <IconBubble tone="bg-orange-50 text-orange-500">
                            <ClipboardX className="size-7" />
                        </IconBubble>
                    }
                    label="Tareas vencidas"
                    value={dashboard.summary.overdue_tasks}
                    hint={
                        dashboard.comparisons?.overdue_tasks?.label ??
                        'Sin comparación'
                    }
                    tone={dashboard.summary.overdue_tasks > 0 ? 'bad' : 'good'}
                />
                <Kpi
                    icon={
                        <IconBubble tone="bg-red-50 text-red-600">
                            <AlertTriangle className="size-7" />
                        </IconBubble>
                    }
                    label="Clientes en riesgo"
                    value={atRisk.length}
                    hint="Requieren atención"
                    tone={atRisk.length > 0 ? 'bad' : 'good'}
                />
                <Kpi
                    icon={
                        <IconBubble tone="bg-violet-50 text-violet-600">
                            <Users className="size-7" />
                        </IconBubble>
                    }
                    label="Equipo activo"
                    value={team.length}
                    hint={
                        overloaded > 0
                            ? `${overloaded} con sobrecarga`
                            : 'Carga balanceada'
                    }
                    tone={overloaded > 0 ? 'bad' : 'good'}
                />
            </section>

            <section className="grid gap-4 xl:grid-cols-[1.4fr_1fr_1fr]">
                <div className={`${card} p-5`}>
                    <CardTitle>Cumplimiento mensual de la firma</CardTitle>
                    <TrendChart points={firm.trend} />
                    <p className="mt-1 text-xs text-slate-500">
                        Empresas con un período cerrado cada mes.
                    </p>
                </div>
                <div className={`${card} p-5`}>
                    <CardTitle>Estado de empresas cliente</CardTitle>
                    <div className="flex items-center gap-4">
                        <Donut counts={counts} total={total} />
                        <ul className="min-w-0 flex-1 space-y-1.5 text-sm">
                            {STATUS_GROUPS.map((group) => (
                                <li
                                    key={group.key}
                                    className="flex items-center gap-2"
                                >
                                    <span
                                        className="size-2.5 shrink-0 rounded-full"
                                        style={{ background: group.color }}
                                    />
                                    <span className="flex-1 truncate text-slate-700">
                                        {group.label}
                                    </span>
                                    <span className="font-black">
                                        {counts[group.key]}
                                    </span>
                                    <span className="w-12 text-right text-xs text-slate-400">
                                        ({pct(counts[group.key], total)}%)
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
                <div className={`${card} p-5`}>
                    <CardTitle>Crecimiento de la firma</CardTitle>
                    <GrowthChart points={firm.growth} />
                    <div className="mt-2 grid grid-cols-3 divide-x divide-slate-100 text-center">
                        <div>
                            <p className="text-lg font-black text-emerald-600">
                                {growthPct >= 0 ? '+' : ''}
                                {growthPct}%
                            </p>
                            <p className="text-xs text-slate-500">Crecimiento</p>
                        </div>
                        <div>
                            <p className="text-lg font-black text-[#8a0b0b]">
                                +{Math.max(0, firm.new_companies)}
                            </p>
                            <p className="text-xs text-slate-500">
                                Nuevos clientes
                            </p>
                        </div>
                        <div>
                            <p className="text-lg font-black text-slate-700">
                                {total}
                            </p>
                            <p className="text-xs text-slate-500">Activos hoy</p>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="cumplimiento-oficinas"
                className="grid gap-4 xl:grid-cols-3"
            >
                <div className={`${card} p-5`}>
                    <CardTitle
                        aside={
                            <Link
                                href="/office/offices"
                                className="rounded-lg border border-[#ebe3df] px-3 py-1 text-xs font-bold text-[#8a0b0b]"
                            >
                                Ver todas las oficinas
                            </Link>
                        }
                    >
                        Cumplimiento por oficina
                    </CardTitle>
                    <div className="space-y-3">
                        {offices.map((office) => (
                            <div
                                key={office.id}
                                className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1"
                            >
                                <p className="truncate text-sm font-bold">
                                    {office.name}
                                </p>
                                <span className="text-xs text-slate-500">
                                    {office.companies} empresas
                                </span>
                                <div className="col-span-2 flex items-center gap-3">
                                    <Bar
                                        value={office.progress}
                                        tone={
                                            office.progress >= 80
                                                ? 'ok'
                                                : office.progress >= 50
                                                  ? 'warn'
                                                  : 'bad'
                                        }
                                    />
                                    <span className="w-10 text-right text-sm font-black">
                                        {office.progress}%
                                    </span>
                                    <span
                                        className={`w-28 truncate text-right text-xs font-bold ${office.overdue > 0 ? 'text-orange-600' : 'text-emerald-700'}`}
                                    >
                                        {office.overdue > 0
                                            ? `${office.overdue} atrasadas`
                                            : 'Operativa'}
                                    </span>
                                </div>
                            </div>
                        ))}
                        {offices.length === 0 && (
                            <p className="text-sm text-slate-500">
                                No hay oficinas activas.
                            </p>
                        )}
                    </div>
                </div>

                <div id="equipo-resumen" className={`${card} p-5`}>
                    <CardTitle
                        aside={
                            <Link
                                href="/office/users-roles"
                                className="rounded-lg border border-[#ebe3df] px-3 py-1 text-xs font-bold text-[#8a0b0b]"
                            >
                                Ver equipo completo
                            </Link>
                        }
                    >
                        Carga de trabajo del equipo
                    </CardTitle>
                    <div className="space-y-3">
                        {team.slice(0, 5).map((person) => {
                            const load = person.utilization_percentage ?? 0;

                            return (
                                <div
                                    key={person.user_id}
                                    className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-bold">
                                            {person.name}
                                        </p>
                                        <p className="truncate text-xs text-slate-500">
                                            {person.role_label ??
                                                person.organization_role}
                                        </p>
                                    </div>
                                    <span
                                        className={`text-xs font-bold ${load >= 90 ? 'text-red-600' : load >= 75 ? 'text-amber-600' : 'text-emerald-700'}`}
                                    >
                                        {load >= 90
                                            ? 'Sobrecarga'
                                            : load >= 75
                                              ? 'Alta'
                                              : 'Normal'}
                                    </span>
                                    <div className="col-span-2 flex items-center gap-3">
                                        <Bar
                                            value={load}
                                            tone={loadTone(load)}
                                        />
                                        <span className="w-10 text-right text-sm font-black">
                                            {load}%
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                        {team.length === 0 && (
                            <p className="text-sm text-slate-500">
                                Sin datos de carga todavía.
                            </p>
                        )}
                    </div>
                </div>

                <div className={`${card} p-5`}>
                    <CardTitle
                        aside={
                            <a
                                href="#cumplimiento"
                                className="rounded-lg border border-[#ebe3df] px-3 py-1 text-xs font-bold text-[#8a0b0b]"
                            >
                                Ver detalle
                            </a>
                        }
                    >
                        Empresas que requieren atención
                    </CardTitle>
                    <div className="space-y-2.5">
                        {atRisk.slice(0, 4).map((company) => (
                            <div
                                key={company.id}
                                className="flex items-start gap-3 rounded-xl border border-slate-100 p-3"
                            >
                                <span className="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full bg-red-50 text-red-600">
                                    <AlertTriangle className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-black">
                                        {company.name}
                                    </p>
                                    <p className="text-xs font-bold text-red-600">
                                        {company.status === 'blocked'
                                            ? 'Cierre bloqueado'
                                            : `${company.overdue_tasks ?? 0} tareas vencidas`}
                                    </p>
                                    <p className="truncate text-xs text-slate-500">
                                        {company.office_name} ·{' '}
                                        {company.accountant ?? 'Sin contador'}
                                    </p>
                                </div>
                            </div>
                        ))}
                        {atRisk.length === 0 && (
                            <p className="flex items-center gap-2 rounded-xl bg-emerald-50 p-4 text-sm font-bold text-emerald-700">
                                <CheckCircle2 className="size-5" /> Ninguna
                                empresa en riesgo.
                            </p>
                        )}
                    </div>
                </div>
            </section>

            <section className="grid gap-4 xl:grid-cols-[1fr_1fr_1fr]">
                <div className={`${card} p-5`}>
                    <CardTitle>Proyección de capacidad</CardTitle>
                    <div className="flex items-center gap-5">
                        <div className="text-center">
                            <Gauge value={capacityUse} />
                            <p className="-mt-1 text-xs text-slate-500">
                                Utilización del plan
                            </p>
                        </div>
                        <dl className="flex-1 space-y-2 text-sm">
                            <div className="flex justify-between gap-3">
                                <dt className="text-slate-500">
                                    Capacidad del plan
                                </dt>
                                <dd className="font-black">
                                    {capacity.limit
                                        ? `${capacity.limit} empresas`
                                        : 'Sin límite'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-slate-500">
                                    Empresas activas
                                </dt>
                                <dd className="font-black">
                                    {capacity.active} empresas
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-slate-500">Disponible</dt>
                                <dd className="font-black text-emerald-600">
                                    {capacity.available === null
                                        ? 'Ilimitada'
                                        : `${capacity.available} empresas`}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <p className="mt-4 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                        {capacity.limit
                            ? `Tu plan permite ${capacity.available} empresas más antes de alcanzar su límite.`
                            : 'Tu plan no limita el número de empresas.'}
                    </p>
                </div>

                <div className={`${card} p-5`}>
                    <CardTitle
                        aside={
                            <Link
                                href="/work/closings"
                                className="rounded-lg border border-[#ebe3df] px-3 py-1 text-xs font-bold text-[#8a0b0b]"
                            >
                                Ver cierres
                            </Link>
                        }
                    >
                        Resumen operativo del mes
                    </CardTitle>
                    <div className="space-y-3">
                        {[
                            ['Empresas al día', counts.ok, 'ok'],
                            ['En revisión', counts.review, 'warn'],
                            ['Con equipo completo', fullTeam, 'ok'],
                            [
                                'Con tareas vencidas',
                                counts.late + counts.blocked,
                                'bad',
                            ],
                        ].map(([label, count, tone]) => (
                            <div key={String(label)} className="flex items-center gap-3">
                                <span className="w-40 shrink-0 text-sm text-slate-700">
                                    {label}
                                </span>
                                <Bar
                                    value={pct(Number(count), total)}
                                    tone={tone as 'ok' | 'warn' | 'bad'}
                                />
                                <span className="w-24 text-right text-xs text-slate-500">
                                    {count}/{total} ·{' '}
                                    <b className="text-[#061633]">
                                        {pct(Number(count), total)}%
                                    </b>
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                <div className={`${card} p-5`}>
                    <CardTitle
                        aside={
                            <a
                                href="#cumplimiento"
                                className="rounded-lg border border-[#ebe3df] px-3 py-1 text-xs font-bold text-[#8a0b0b]"
                            >
                                Ver todos
                            </a>
                        }
                    >
                        Próximos vencimientos
                    </CardTitle>
                    <div className="space-y-2.5">
                        {upcoming.map((task) => (
                            <div
                                key={task.id}
                                className="flex items-center gap-3 rounded-xl border border-slate-100 p-3"
                            >
                                <span
                                    className={`grid size-9 shrink-0 place-items-center rounded-full ${task.is_overdue ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-600'}`}
                                >
                                    <CalendarDays className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-black">
                                        {task.title}
                                    </p>
                                    <p className="truncate text-xs text-slate-500">
                                        {task.company_name ?? 'Firma'}
                                    </p>
                                </div>
                                <span
                                    className={`text-xs font-bold ${task.is_overdue ? 'text-red-600' : 'text-slate-500'}`}
                                >
                                    {task.remaining_label ?? ''}
                                </span>
                            </div>
                        ))}
                        {upcoming.length === 0 && (
                            <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
                                No hay vencimientos próximos.
                            </p>
                        )}
                    </div>
                </div>
            </section>
        </div>
    );
}
