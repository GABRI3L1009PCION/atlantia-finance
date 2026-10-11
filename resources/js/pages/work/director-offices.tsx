import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownUp,
    Building2,
    CheckCircle2,
    Mail,
    MapPin,
    Phone,
    Search,
    UserRound,
    UsersRound,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type OfficeStatus = 'operational' | 'attention' | 'inactive';

type DirectorOffice = {
    id: number;
    code: string;
    name: string;
    city: string | null;
    state: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    is_primary: boolean;
    status: OfficeStatus;
    responsible: string | null;
    responsible_role: string | null;
    companies: number;
    completed_companies: number;
    progress: number;
    overdue: number;
    critical: number;
    workload: number;
    team: { name: string; role: string }[];
    company_list: {
        name: string;
        status: string;
        progress: number;
        overdue_tasks: number;
    }[];
};

type Props = {
    summary: {
        total: number;
        active: number;
        attention: number;
        compliance: number;
        companies: number;
    };
    offices: DirectorOffice[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const control =
    'h-10 rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm text-[#2b2f3a] outline-none focus:border-[#8a0b0b]';

const STATUS_LABEL: Record<OfficeStatus, string> = {
    operational: 'Operativa',
    attention: 'Requiere atención',
    inactive: 'Inactiva',
};
const STATUS_COLOR: Record<OfficeStatus, string> = {
    operational: '#16a34a',
    attention: '#dc2626',
    inactive: '#94a3b8',
};

/** [longitude, latitude] of the Guatemalan cities where firms usually have offices. */
const CITIES: Record<string, [number, number]> = {
    'ciudad de guatemala': [-90.51, 14.63],
    guatemala: [-90.51, 14.63],
    antigua: [-90.73, 14.56],
    quetzaltenango: [-91.52, 14.84],
    escuintla: [-90.79, 14.3],
    chimaltenango: [-90.82, 14.66],
    flores: [-89.89, 16.93],
    peten: [-89.89, 16.93],
    'puerto barrios': [-88.6, 15.73],
    'santo tomas de castilla': [-88.61, 15.69],
    izabal: [-89.02, 15.4],
    livingston: [-88.75, 15.83],
    zacapa: [-89.53, 14.97],
    coban: [-90.37, 15.47],
    huehuetenango: [-91.47, 15.32],
    retalhuleu: [-91.69, 14.54],
    mazatenango: [-91.5, 14.53],
    jalapa: [-89.99, 14.63],
    jutiapa: [-89.89, 14.29],
    chiquimula: [-89.54, 14.8],
    esquipulas: [-89.35, 14.56],
    solola: [-91.18, 14.77],
    totonicapan: [-91.36, 14.91],
    'san marcos': [-91.8, 14.97],
    salama: [-90.32, 15.1],
    quiche: [-91.15, 15.03],
};

const OUTLINE: [number, number][] = [
    [-91.4, 17.82],
    [-89.15, 17.82],
    [-89.14, 15.9],
    [-88.6, 15.88],
    [-88.23, 15.73],
    [-88.34, 15.64],
    [-88.8, 15.35],
    [-89.15, 14.98],
    [-89.35, 14.43],
    [-89.7, 14.05],
    [-89.85, 13.85],
    [-90.1, 13.73],
    [-90.6, 13.93],
    [-91.25, 13.92],
    [-91.7, 14.1],
    [-92.23, 14.54],
    [-92.2, 15.25],
    [-91.73, 16.07],
    [-90.98, 16.15],
    [-90.44, 16.08],
    [-90.4, 16.41],
    [-90.7, 16.7],
    [-91.18, 16.95],
    [-91.43, 17.25],
];

const MAP_W = 320;
const MAP_H = 300;
const project = ([lon, lat]: [number, number]): [number, number] => [
    ((lon + 92.4) / 4.4) * MAP_W,
    ((18 - lat) / 4.4) * MAP_H,
];

function normalize(value: string): string {
    return value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function locate(office: DirectorOffice): [number, number] | null {
    const haystack = normalize(`${office.city ?? ''} ${office.name}`);
    const key = Object.keys(CITIES)
        .sort((a, b) => b.length - a.length)
        .find((city) => haystack.includes(city));

    return key ? project(CITIES[key]) : null;
}

function StatusBadge({ status }: { status: OfficeStatus }) {
    const styles: Record<OfficeStatus, string> = {
        operational: 'bg-emerald-50 text-emerald-700',
        attention: 'bg-red-50 text-red-700',
        inactive: 'bg-slate-100 text-slate-600',
    };

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-bold ${styles[status]}`}
        >
            <span
                className="size-2 rounded-full"
                style={{ background: STATUS_COLOR[status] }}
            />
            {STATUS_LABEL[status]}
        </span>
    );
}

function Meter({ value, invert }: { value: number; invert?: boolean }) {
    const high = invert ? value >= 85 : value < 60;
    const mid = invert ? value >= 70 : value < 85;
    const color = high ? '#dc2626' : mid ? '#f0a93b' : '#16a34a';

    return (
        <div className="flex items-center gap-2">
            <span className="w-10 text-sm font-bold">{value}%</span>
            <div className="h-2 w-20 overflow-hidden rounded-full bg-slate-100">
                <div
                    className="h-full rounded-full"
                    style={{
                        width: `${Math.min(100, value)}%`,
                        background: color,
                    }}
                />
            </div>
        </div>
    );
}

function Kpi({
    icon,
    tone,
    label,
    value,
    hint,
}: {
    icon: ReactNode;
    tone: string;
    label: string;
    value: ReactNode;
    hint: string;
}) {
    return (
        <div className={`${card} flex items-center gap-4 p-4`}>
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
                <p className="truncate text-xs font-semibold text-slate-500">
                    {hint}
                </p>
            </div>
        </div>
    );
}

function OfficeMap({
    offices,
    selectedId,
    onSelect,
}: {
    offices: DirectorOffice[];
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    const outline = OUTLINE.map((point) => project(point).join(',')).join(' ');
    const taken: [number, number][] = [];
    const placed = offices
        .map((office) => {
            const point = locate(office);

            if (point === null) {
                return { office, point };
            }

            // Offices in the same city would overlap; fan them out a little.
            const crowded = taken.filter(
                ([x, y]) =>
                    Math.abs(x - point[0]) < 10 && Math.abs(y - point[1]) < 10,
            ).length;
            taken.push(point);

            return {
                office,
                point: [point[0], point[1] + crowded * 13] as [number, number],
            };
        })
        .filter(
            (
                item,
            ): item is { office: DirectorOffice; point: [number, number] } =>
                item.point !== null,
        );
    const unplaced = offices.length - placed.length;
    const counts = (status: OfficeStatus) =>
        offices.filter((office) => office.status === status).length;

    return (
        <div>
            <svg
                viewBox={`0 0 ${MAP_W} ${MAP_H}`}
                className="w-full rounded-xl bg-[#f3f6f9]"
            >
                <polygon
                    points={outline}
                    fill="#dfe6ee"
                    stroke="#b8c4d2"
                    strokeWidth="1.5"
                    strokeLinejoin="round"
                />
                {placed.map(({ office, point }) => (
                    <g
                        key={office.id}
                        className="cursor-pointer"
                        onClick={() => onSelect(office.id)}
                    >
                        <circle
                            cx={point[0]}
                            cy={point[1]}
                            r={selectedId === office.id ? 11 : 8}
                            fill={STATUS_COLOR[office.status]}
                            opacity="0.22"
                        />
                        <circle
                            cx={point[0]}
                            cy={point[1]}
                            r="5"
                            fill={STATUS_COLOR[office.status]}
                            stroke="#fff"
                            strokeWidth="1.5"
                        />
                        <text
                            x={point[0] + 9}
                            y={point[1] + 3}
                            className="fill-[#061633] text-[9px] font-bold"
                        >
                            {office.city ?? office.name}
                        </text>
                    </g>
                ))}
            </svg>
            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                {(['operational', 'attention', 'inactive'] as const).map(
                    (status) => (
                        <span
                            key={status}
                            className="flex items-center gap-1.5"
                        >
                            <span
                                className="size-2.5 rounded-full"
                                style={{ background: STATUS_COLOR[status] }}
                            />
                            {STATUS_LABEL[status]} ({counts(status)})
                        </span>
                    ),
                )}
            </div>
            {unplaced > 0 && (
                <p className="mt-1 text-xs text-slate-400">
                    {unplaced} oficina(s) sin ubicación reconocida en el mapa.
                </p>
            )}
        </div>
    );
}

const TABS = ['Desempeño', 'Empresas', 'Equipo'] as const;

function OfficeDetail({ office }: { office: DirectorOffice }) {
    const [tab, setTab] = useState<(typeof TABS)[number]>('Desempeño');

    return (
        <div>
            <div className="flex items-start gap-3">
                <span className="grid size-16 shrink-0 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                    <Building2 className="size-8" />
                </span>
                <div className="min-w-0 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-lg font-black">{office.name}</h3>
                        <StatusBadge status={office.status} />
                    </div>
                    <p className="text-xs text-slate-500">{office.code}</p>
                    <ul className="mt-2 space-y-1 text-xs text-slate-600">
                        {(office.address || office.city) && (
                            <li className="flex items-center gap-1.5">
                                <MapPin className="size-3.5 text-[#8a0b0b]" />
                                {[office.address, office.city, office.state]
                                    .filter(Boolean)
                                    .join(', ')}
                            </li>
                        )}
                        {office.phone && (
                            <li className="flex items-center gap-1.5">
                                <Phone className="size-3.5 text-[#8a0b0b]" />
                                {office.phone}
                            </li>
                        )}
                        {office.email && (
                            <li className="flex items-center gap-1.5">
                                <Mail className="size-3.5 text-[#8a0b0b]" />
                                {office.email}
                            </li>
                        )}
                        <li className="flex items-center gap-1.5">
                            <UserRound className="size-3.5 text-[#8a0b0b]" />
                            {office.responsible ?? 'Sin responsable'}
                            {office.responsible_role
                                ? ` | ${office.responsible_role}`
                                : ''}
                        </li>
                    </ul>
                </div>
            </div>

            <div className="mt-4 flex gap-5 border-b border-slate-100 text-sm font-bold">
                {TABS.map((name) => (
                    <button
                        key={name}
                        type="button"
                        onClick={() => setTab(name)}
                        className={`-mb-px border-b-2 pb-2 ${tab === name ? 'border-[#8a0b0b] text-[#8a0b0b]' : 'border-transparent text-slate-500'}`}
                    >
                        {name}
                        {name === 'Equipo' ? ` (${office.team.length})` : ''}
                    </button>
                ))}
            </div>

            {tab === 'Desempeño' && (
                <div className="mt-4 grid grid-cols-[auto_1fr] items-center gap-4">
                    <div className="text-center">
                        <div
                            className="grid size-24 place-items-center rounded-full"
                            style={{
                                background: `conic-gradient(#16a34a ${office.progress * 3.6}deg, #eef1f3 0)`,
                            }}
                        >
                            <span className="grid size-[4.6rem] place-items-center rounded-full bg-white text-xl font-black">
                                {office.progress}%
                            </span>
                        </div>
                        <p className="mt-1 text-xs font-bold">Cumplimiento</p>
                    </div>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dd className="text-xl font-black">
                                {office.companies}
                            </dd>
                            <dt className="text-xs text-slate-500">
                                Empresas cliente
                            </dt>
                        </div>
                        <div>
                            <dd className="text-xl font-black">
                                {office.team.length}
                            </dd>
                            <dt className="text-xs text-slate-500">
                                Miembros de equipo
                            </dt>
                        </div>
                        <div>
                            <dd className="text-xl font-black">
                                {office.workload}%
                            </dd>
                            <dt className="text-xs text-slate-500">
                                Carga de trabajo
                            </dt>
                        </div>
                        <div>
                            <dd
                                className={`text-xl font-black ${office.overdue > 0 ? 'text-red-600' : ''}`}
                            >
                                {office.overdue}
                            </dd>
                            <dt className="text-xs text-slate-500">
                                Empresas atrasadas
                            </dt>
                        </div>
                    </dl>
                </div>
            )}

            {tab === 'Empresas' && (
                <ul className="mt-3 max-h-56 space-y-2 overflow-y-auto text-sm">
                    {office.company_list.map((company) => (
                        <li
                            key={company.name}
                            className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"
                        >
                            <span className="truncate font-bold">
                                {company.name}
                            </span>
                            <span
                                className={`text-xs font-bold ${company.overdue_tasks > 0 ? 'text-red-600' : 'text-slate-500'}`}
                            >
                                {company.overdue_tasks > 0
                                    ? `${company.overdue_tasks} vencidas`
                                    : `${company.progress}%`}
                            </span>
                        </li>
                    ))}
                    {office.company_list.length === 0 && (
                        <li className="text-slate-500">
                            Sin empresas asignadas.
                        </li>
                    )}
                </ul>
            )}

            {tab === 'Equipo' && (
                <ul className="mt-3 max-h-56 space-y-2 overflow-y-auto text-sm">
                    {office.team.map((member) => (
                        <li
                            key={member.name}
                            className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"
                        >
                            <span className="font-bold">{member.name}</span>
                            <span className="text-xs text-slate-500">
                                {member.role}
                            </span>
                        </li>
                    ))}
                    {office.team.length === 0 && (
                        <li className="text-slate-500">Sin colaboradores.</li>
                    )}
                </ul>
            )}
        </div>
    );
}

export default function DirectorOffices({ summary, offices }: Props) {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'all' | OfficeStatus>('all');
    const [sort, setSort] = useState<'progress' | 'name' | 'companies'>(
        'progress',
    );
    const [descending, setDescending] = useState(true);
    const [selectedId, setSelectedId] = useState<number | null>(
        offices[0]?.id ?? null,
    );

    const visible = useMemo(() => {
        const term = normalize(search.trim());

        return offices
            .filter(
                (office) =>
                    (status === 'all' || office.status === status) &&
                    (term === '' ||
                        normalize(
                            `${office.name} ${office.city ?? ''} ${office.responsible ?? ''}`,
                        ).includes(term)),
            )
            .sort((a, b) => {
                const order =
                    sort === 'name'
                        ? a.name.localeCompare(b.name)
                        : a[sort] - b[sort];

                return descending ? -order : order;
            });
    }, [offices, search, status, sort, descending]);
    const selected =
        offices.find((office) => office.id === selectedId) ??
        offices[0] ??
        null;

    return (
        <>
            <Head title="Oficinas" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1500px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                            <Building2 className="size-7" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">
                                Oficinas
                            </h1>
                            <p className="text-sm text-slate-600">
                                Consulta tus oficinas, equipos y desempeño
                                operativo.
                            </p>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi
                            icon={<Building2 className="size-7" />}
                            tone="bg-[#f6e6e4] text-[#8a0b0b]"
                            label="Total de oficinas"
                            value={summary.total}
                            hint="Registradas en la firma"
                        />
                        <Kpi
                            icon={<CheckCircle2 className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Oficinas activas"
                            value={summary.active}
                            hint={`${summary.total ? Math.round((summary.active / summary.total) * 100) : 0}% del total`}
                        />
                        <Kpi
                            icon={<AlertTriangle className="size-7" />}
                            tone="bg-red-50 text-red-600"
                            label="Requieren atención"
                            value={summary.attention}
                            hint={`${summary.total ? Math.round((summary.attention / summary.total) * 100) : 0}% del total`}
                        />
                        <Kpi
                            icon={
                                <span className="text-sm font-black">
                                    {summary.compliance}%
                                </span>
                            }
                            tone="bg-blue-50 text-blue-600"
                            label="Cumplimiento promedio"
                            value={`${summary.compliance}%`}
                            hint="Avance del período"
                        />
                        <Kpi
                            icon={<UsersRound className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="Empresas asignadas"
                            value={summary.companies}
                            hint="En todas las oficinas"
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
                        <div className={`${card} min-w-0 p-5`}>
                            <h2 className="mb-3 text-lg font-black">
                                Listado de oficinas
                            </h2>
                            <div className="mb-4 flex flex-wrap gap-2">
                                <label className="relative min-w-52 flex-1">
                                    <Search className="absolute top-3 left-3 size-4 text-slate-400" />
                                    <input
                                        className={`${control} w-full pl-9`}
                                        placeholder="Buscar oficina, ciudad o responsable..."
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                    />
                                </label>
                                <select
                                    className={control}
                                    value={status}
                                    onChange={(event) =>
                                        setStatus(
                                            event.target.value as
                                                'all' | OfficeStatus,
                                        )
                                    }
                                >
                                    <option value="all">
                                        Todos los estados
                                    </option>
                                    <option value="operational">
                                        Operativa
                                    </option>
                                    <option value="attention">
                                        Requiere atención
                                    </option>
                                    <option value="inactive">Inactiva</option>
                                </select>
                                <select
                                    className={control}
                                    value={sort}
                                    onChange={(event) =>
                                        setSort(
                                            event.target.value as
                                                | 'progress'
                                                | 'name'
                                                | 'companies',
                                        )
                                    }
                                >
                                    <option value="progress">
                                        Cumplimiento
                                    </option>
                                    <option value="companies">Empresas</option>
                                    <option value="name">Nombre</option>
                                </select>
                                <button
                                    type="button"
                                    aria-label="Invertir orden"
                                    onClick={() =>
                                        setDescending((value) => !value)
                                    }
                                    className={`${control} grid place-items-center`}
                                >
                                    <ArrowDownUp className="size-4" />
                                </button>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[820px] text-sm">
                                    <thead>
                                        <tr className="bg-slate-50 text-left text-xs text-slate-600">
                                            <th className="px-3 py-2">
                                                Oficina
                                            </th>
                                            <th className="px-3 py-2">
                                                Ciudad
                                            </th>
                                            <th className="px-3 py-2">
                                                Responsable
                                            </th>
                                            <th className="px-3 py-2 text-center">
                                                Empresas
                                            </th>
                                            <th className="px-3 py-2 text-center">
                                                Equipo
                                            </th>
                                            <th className="px-3 py-2">
                                                Cumplimiento
                                            </th>
                                            <th className="px-3 py-2">Carga</th>
                                            <th className="px-3 py-2">
                                                Estado
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {visible.map((office) => (
                                            <tr
                                                key={office.id}
                                                onClick={() =>
                                                    setSelectedId(office.id)
                                                }
                                                className={`cursor-pointer transition hover:bg-[#fbf5f4] ${selected?.id === office.id ? 'bg-red-50/60' : ''}`}
                                            >
                                                <td className="px-3 py-3">
                                                    <p className="font-black">
                                                        {office.name}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {office.code}
                                                        {office.is_primary
                                                            ? ' · Principal'
                                                            : ''}
                                                    </p>
                                                </td>
                                                <td className="px-3 py-3 text-slate-600">
                                                    {office.city ?? '—'}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <p className="font-bold">
                                                        {office.responsible ??
                                                            'Sin responsable'}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {
                                                            office.responsible_role
                                                        }
                                                    </p>
                                                </td>
                                                <td className="px-3 py-3 text-center font-bold">
                                                    {office.companies}
                                                </td>
                                                <td className="px-3 py-3 text-center font-bold">
                                                    {office.team.length}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <Meter
                                                        value={office.progress}
                                                    />
                                                </td>
                                                <td className="px-3 py-3">
                                                    <Meter
                                                        value={office.workload}
                                                        invert
                                                    />
                                                </td>
                                                <td className="px-3 py-3">
                                                    <StatusBadge
                                                        status={office.status}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                        {visible.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={8}
                                                    className="px-3 py-10 text-center text-slate-500"
                                                >
                                                    No hay oficinas con esos
                                                    criterios.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <p className="mt-3 text-xs text-slate-500">
                                Mostrando {visible.length} de {offices.length}{' '}
                                oficinas
                            </p>
                        </div>

                        <div className="space-y-4">
                            <div className={`${card} p-5`}>
                                <h2 className="mb-3 text-lg font-black">
                                    Distribución geográfica de oficinas
                                </h2>
                                <OfficeMap
                                    offices={offices}
                                    selectedId={selected?.id ?? null}
                                    onSelect={setSelectedId}
                                />
                            </div>
                            {selected && (
                                <div className={`${card} p-5`}>
                                    <h2 className="mb-3 text-lg font-black">
                                        Detalle de la oficina
                                    </h2>
                                    <OfficeDetail
                                        key={selected.id}
                                        office={selected}
                                    />
                                </div>
                            )}
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
