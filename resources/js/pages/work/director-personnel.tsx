import { Head } from '@inertiajs/react';
import {
    Building2,
    Download,
    Layers,
    Mail,
    Search,
    UserCheck,
    UserX,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

type Person = {
    id: number;
    name: string;
    email: string;
    role: string;
    offices: string[];
    companies: number;
    load: number | null;
    punctuality: number | null;
    completed: number;
    open: number;
    active: boolean;
    joined_at: string | null;
    activity: { action: string; at: string }[];
};

type Props = {
    summary: {
        total: number;
        active: number;
        inactive: number;
        roles: number;
        load: number;
    };
    people: Person[];
    roles: string[];
    offices: string[];
};

const card =
    'rounded-2xl border border-[#ebe3df] bg-white shadow-[0_10px_28px_-22px_rgba(49,11,8,.55)]';
const control =
    'h-10 rounded-xl border border-[#e4dbd6] bg-white px-3 text-sm outline-none focus:border-[#8a0b0b]';
const PAGE_SIZE = 10;
const AVATAR = [
    'bg-blue-100 text-blue-700',
    'bg-emerald-100 text-emerald-700',
    'bg-amber-100 text-amber-700',
    'bg-violet-100 text-violet-700',
    'bg-rose-100 text-rose-700',
];

function normalize(value: string): string {
    return value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function formatDate(value: string | null, withTime = false): string {
    if (!value) {
        return '—';
    }

    return new Date(
        value.length === 10 ? `${value}T12:00:00` : value,
    ).toLocaleDateString(
        'es-GT',
        withTime
            ? {
                  day: '2-digit',
                  month: 'short',
                  hour: '2-digit',
                  minute: '2-digit',
              }
            : { day: '2-digit', month: 'short', year: 'numeric' },
    );
}

/** "office.created" -> "Office created": the raw audit action, readable enough without inventing wording. */
function actionLabel(action: string): string {
    const text = action.replaceAll('.', ' · ').replaceAll('_', ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function Avatar({ person, large }: { person: Person; large?: boolean }) {
    return (
        <span
            className={`grid shrink-0 place-items-center rounded-full font-black ${large ? 'size-16 text-xl' : 'size-10 text-sm'} ${AVATAR[person.id % AVATAR.length]}`}
        >
            {initials(person.name)}
        </span>
    );
}

function LoadBar({ value }: { value: number | null }) {
    if (value === null) {
        return <span className="text-slate-400">—</span>;
    }

    const color = value >= 90 ? '#dc2626' : value >= 75 ? '#f0a93b' : '#16a34a';

    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-20 overflow-hidden rounded-full bg-slate-100">
                <div
                    className="h-full rounded-full"
                    style={{
                        width: `${Math.min(100, value)}%`,
                        background: color,
                    }}
                />
            </div>
            <span className="w-9 text-xs font-bold">{value}%</span>
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
    value: string;
    hint: string;
}) {
    return (
        <div className={`${card} flex min-w-0 items-center gap-3 p-4`}>
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

function Detail({ person, onClose }: { person: Person; onClose: () => void }) {
    const [tab, setTab] = useState<'Resumen' | 'Actividad'>('Resumen');

    return (
        <aside className={`${card} space-y-4 p-5 xl:sticky xl:top-4`}>
            <div className="flex items-start gap-3">
                <Avatar person={person} large />
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg font-black">{person.name}</h2>
                    <p className="text-sm text-slate-600">{person.role}</p>
                    <span
                        className={`mt-1 inline-block rounded-lg px-2.5 py-0.5 text-xs font-bold ${person.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}
                    >
                        {person.active ? 'Activo' : 'Inactivo'}
                    </span>
                </div>
                <button
                    type="button"
                    aria-label="Cerrar detalle"
                    onClick={onClose}
                    className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"
                >
                    <X className="size-5" />
                </button>
            </div>

            <div className="flex gap-5 border-b border-slate-100 text-sm font-bold">
                {(['Resumen', 'Actividad'] as const).map((name) => (
                    <button
                        key={name}
                        type="button"
                        onClick={() => setTab(name)}
                        className={`-mb-px border-b-2 pb-2 ${tab === name ? 'border-[#8a0b0b] text-[#8a0b0b]' : 'border-transparent text-slate-500'}`}
                    >
                        {name}
                    </button>
                ))}
            </div>

            {tab === 'Resumen' && (
                <div className="space-y-4 text-sm">
                    <section>
                        <h3 className="mb-2 font-black">
                            Información de contacto
                        </h3>
                        <ul className="space-y-1.5 text-slate-700">
                            <li className="flex items-center gap-2">
                                <Mail className="size-4 text-[#8a0b0b]" />
                                {person.email}
                            </li>
                            <li className="flex items-center gap-2">
                                <Building2 className="size-4 text-[#8a0b0b]" />
                                {person.offices.length
                                    ? person.offices.join(', ')
                                    : 'Sin oficina asignada'}
                            </li>
                            <li className="text-xs text-slate-500">
                                Ingreso: {formatDate(person.joined_at)}
                            </li>
                        </ul>
                    </section>
                    <section>
                        <h3 className="mb-2 font-black">
                            Resumen de productividad
                        </h3>
                        <dl className="grid grid-cols-3 gap-2 text-center">
                            <div className="rounded-xl bg-slate-50 p-3">
                                <dd className="text-xl font-black">
                                    {person.load === null
                                        ? '—'
                                        : `${person.load}%`}
                                </dd>
                                <dt className="text-xs text-slate-500">
                                    Utilización
                                </dt>
                            </div>
                            <div className="rounded-xl bg-slate-50 p-3">
                                <dd className="text-xl font-black">
                                    {person.punctuality === null
                                        ? '—'
                                        : `${person.punctuality}%`}
                                </dd>
                                <dt className="text-xs text-slate-500">
                                    Puntualidad
                                </dt>
                            </div>
                            <div className="rounded-xl bg-slate-50 p-3">
                                <dd className="text-xl font-black">
                                    {person.completed}
                                </dd>
                                <dt className="text-xs text-slate-500">
                                    Terminadas
                                </dt>
                            </div>
                        </dl>
                        <p className="mt-2 text-xs text-slate-500">
                            {person.companies} empresa(s) asignada(s) ·{' '}
                            {person.open} tarea(s) abierta(s)
                        </p>
                    </section>
                </div>
            )}

            {tab === 'Actividad' && (
                <ul className="space-y-3 text-sm">
                    {person.activity.map((entry, index) => (
                        <li
                            key={`${entry.action}-${index}`}
                            className="flex items-start justify-between gap-3"
                        >
                            <span className="font-semibold">
                                {actionLabel(entry.action)}
                            </span>
                            <span className="shrink-0 text-xs text-slate-500">
                                {formatDate(entry.at, true)}
                            </span>
                        </li>
                    ))}
                    {person.activity.length === 0 && (
                        <li className="text-slate-500">
                            Sin actividad registrada.
                        </li>
                    )}
                </ul>
            )}
        </aside>
    );
}

function exportCsv(rows: Person[]) {
    const header = [
        'Colaborador',
        'Correo',
        'Rol',
        'Oficinas',
        'Empresas',
        'Carga %',
        'Puntualidad %',
        'Estado',
    ].join(',');
    const lines = rows.map((person) =>
        [
            person.name,
            person.email,
            person.role,
            person.offices.join(' / '),
            person.companies,
            person.load ?? '',
            person.punctuality ?? '',
            person.active ? 'Activo' : 'Inactivo',
        ]
            .map((cell) => `"${String(cell).replaceAll('"', '""')}"`)
            .join(','),
    );
    const blob = new Blob(['﻿' + [header, ...lines].join('\n')], {
        type: 'text/csv;charset=utf-8',
    });
    const link = document.createElement('a');

    link.href = URL.createObjectURL(blob);
    link.download = 'personal.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function DirectorPersonnel({
    summary,
    people,
    roles,
    offices,
}: Props) {
    const [search, setSearch] = useState('');
    const [role, setRole] = useState('');
    const [office, setOffice] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [selectedId, setSelectedId] = useState<number | null>(
        people[0]?.id ?? null,
    );

    const filtered = useMemo(() => {
        const term = normalize(search.trim());

        return people.filter(
            (person) =>
                (term === '' ||
                    normalize(
                        `${person.name} ${person.email} ${person.role}`,
                    ).includes(term)) &&
                (role === '' || person.role === role) &&
                (office === '' || person.offices.includes(office)) &&
                (status === '' || (status === 'active') === person.active),
        );
    }, [people, search, role, office, status]);
    const pages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const current = Math.min(page, pages);
    const visible = filtered.slice(
        (current - 1) * PAGE_SIZE,
        current * PAGE_SIZE,
    );
    const selected = people.find((person) => person.id === selectedId) ?? null;
    const reset = (apply: () => void) => {
        apply();
        setPage(1);
    };

    return (
        <>
            <Head title="Personal" />
            <main className="min-h-full flex-1 bg-[#faf8f7] p-4 text-[#152033] sm:p-6">
                <div className="mx-auto max-w-[1600px] space-y-4">
                    <header className="flex items-center gap-3">
                        <span className="grid size-12 place-items-center rounded-xl bg-[#f6e6e4] text-[#8a0b0b]">
                            <Users className="size-7" />
                        </span>
                        <div>
                            <h1 className="text-3xl font-black tracking-tight">
                                Personal
                            </h1>
                            <p className="text-sm text-slate-600">
                                Consulta el talento de tu firma contable.
                            </p>
                        </div>
                    </header>

                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <Kpi
                            icon={<Users className="size-7" />}
                            tone="bg-blue-50 text-blue-600"
                            label="Total de colaboradores"
                            value={String(summary.total)}
                            hint="En toda la firma"
                        />
                        <Kpi
                            icon={<UserCheck className="size-7" />}
                            tone="bg-emerald-50 text-emerald-600"
                            label="Usuarios activos"
                            value={String(summary.active)}
                            hint={`${summary.total ? Math.round((summary.active / summary.total) * 100) : 0}% del total`}
                        />
                        <Kpi
                            icon={<Layers className="size-7" />}
                            tone="bg-orange-50 text-orange-500"
                            label="Roles ocupados"
                            value={String(summary.roles)}
                            hint="Tipos de rol en uso"
                        />
                        <Kpi
                            icon={<UserX className="size-7" />}
                            tone="bg-rose-50 text-rose-500"
                            label="Usuarios inactivos"
                            value={String(summary.inactive)}
                            hint={`${summary.total ? Math.round((summary.inactive / summary.total) * 100) : 0}% del total`}
                        />
                        <Kpi
                            icon={<Building2 className="size-7" />}
                            tone="bg-violet-50 text-violet-600"
                            label="Utilización promedio"
                            value={`${summary.load}%`}
                            hint="Carga del equipo activo"
                        />
                    </section>

                    <section
                        className={`grid gap-4 ${selected ? 'xl:grid-cols-[minmax(0,1fr)_400px]' : ''}`}
                    >
                        <div className={`${card} min-w-0 p-5`}>
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <h2 className="text-lg font-black">
                                    Directorio de personal
                                </h2>
                                <button
                                    type="button"
                                    onClick={() => exportCsv(filtered)}
                                    className="inline-flex items-center gap-2 rounded-xl border border-[#e4dbd6] px-4 py-2 text-sm font-bold"
                                >
                                    <Download className="size-4" /> Exportar
                                </button>
                            </div>
                            <div className="mb-4 flex flex-wrap gap-2">
                                <label className="relative min-w-52 flex-1">
                                    <Search className="absolute top-3 left-3 size-4 text-slate-400" />
                                    <input
                                        className={`${control} w-full pl-9`}
                                        placeholder="Buscar por nombre, correo o rol..."
                                        value={search}
                                        onChange={(event) =>
                                            reset(() =>
                                                setSearch(event.target.value),
                                            )
                                        }
                                    />
                                </label>
                                <select
                                    className={control}
                                    value={role}
                                    onChange={(event) =>
                                        reset(() => setRole(event.target.value))
                                    }
                                >
                                    <option value="">Todos los roles</option>
                                    {roles.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className={control}
                                    value={office}
                                    onChange={(event) =>
                                        reset(() =>
                                            setOffice(event.target.value),
                                        )
                                    }
                                >
                                    <option value="">Todas las oficinas</option>
                                    {offices.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className={control}
                                    value={status}
                                    onChange={(event) =>
                                        reset(() =>
                                            setStatus(event.target.value),
                                        )
                                    }
                                >
                                    <option value="">Todos los estados</option>
                                    <option value="active">Activo</option>
                                    <option value="inactive">Inactivo</option>
                                </select>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[820px] text-sm">
                                    <thead>
                                        <tr className="text-left text-xs text-slate-600">
                                            <th className="px-2 py-2">
                                                Colaborador
                                            </th>
                                            <th className="px-2 py-2">Rol</th>
                                            <th className="px-2 py-2">
                                                Oficina asignada
                                            </th>
                                            <th className="px-2 py-2">
                                                Empresas asignadas
                                            </th>
                                            <th className="px-2 py-2">
                                                Carga actual
                                            </th>
                                            <th className="px-2 py-2">
                                                Puntualidad
                                            </th>
                                            <th className="px-2 py-2">
                                                Estado
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {visible.map((person) => (
                                            <tr
                                                key={person.id}
                                                onClick={() =>
                                                    setSelectedId(person.id)
                                                }
                                                className={`cursor-pointer hover:bg-[#fbf5f4] ${selectedId === person.id ? 'bg-red-50/60' : ''}`}
                                            >
                                                <td className="px-2 py-3">
                                                    <div className="flex items-center gap-3">
                                                        <Avatar
                                                            person={person}
                                                        />
                                                        <div className="min-w-0">
                                                            <p className="font-black">
                                                                {person.name}
                                                            </p>
                                                            <p className="truncate text-xs text-slate-500">
                                                                {person.email}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-2 py-3">
                                                    <span className="rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700">
                                                        {person.role}
                                                    </span>
                                                </td>
                                                <td className="px-2 py-3 text-slate-600">
                                                    {person.offices.join(
                                                        ', ',
                                                    ) || '—'}
                                                </td>
                                                <td className="px-2 py-3">
                                                    {person.companies}{' '}
                                                    empresa(s)
                                                </td>
                                                <td className="px-2 py-3">
                                                    <LoadBar
                                                        value={person.load}
                                                    />
                                                </td>
                                                <td className="px-2 py-3">
                                                    {person.punctuality ===
                                                    null ? (
                                                        <span className="text-slate-400">
                                                            —
                                                        </span>
                                                    ) : (
                                                        <span className="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                                            {person.punctuality}
                                                            %
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-2 py-3">
                                                    <span
                                                        className={`rounded-lg px-3 py-1 text-xs font-bold ${person.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}
                                                    >
                                                        {person.active
                                                            ? 'Activo'
                                                            : 'Inactivo'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                        {visible.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={7}
                                                    className="px-3 py-10 text-center text-slate-500"
                                                >
                                                    No hay colaboradores con
                                                    esos criterios.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
                                <span>
                                    Mostrando{' '}
                                    {filtered.length === 0
                                        ? 0
                                        : (current - 1) * PAGE_SIZE + 1}
                                    –
                                    {Math.min(
                                        current * PAGE_SIZE,
                                        filtered.length,
                                    )}{' '}
                                    de {filtered.length} colaboradores
                                </span>
                                <div className="flex gap-1">
                                    {Array.from(
                                        { length: pages },
                                        (_, index) => (
                                            <button
                                                key={index}
                                                type="button"
                                                onClick={() =>
                                                    setPage(index + 1)
                                                }
                                                className={`size-8 rounded-lg text-sm font-bold ${current === index + 1 ? 'bg-[#8a0b0b] text-white' : 'border border-[#e4dbd6] text-slate-600'}`}
                                            >
                                                {index + 1}
                                            </button>
                                        ),
                                    )}
                                </div>
                            </div>
                        </div>

                        {selected && (
                            <Detail
                                key={selected.id}
                                person={selected}
                                onClose={() => setSelectedId(null)}
                            />
                        )}
                    </section>
                </div>
            </main>
        </>
    );
}
