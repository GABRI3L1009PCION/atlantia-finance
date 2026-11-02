import { Head, Link, router } from '@inertiajs/react';
import {
    BadgeCheck,
    Building2,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Download,
    Eye,
    Layers,
    MapPin,
    Search,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import {
    Notice,
    Pill,
    RowMenu,
    ccInput,
    date,
    statusTone,
} from '@/components/control-center/kit';

type Office = {
    id: number;
    code: string;
    name: string;
    city: string | null;
    status: string;
    primary: boolean;
    organization_id: number;
    organization_public_id: string | null;
    organization: string;
    organization_code: string | null;
    organization_tax_id: string | null;
    users: number;
    companies: number;
    created_at: string;
};

const PAGE_SIZE = 10;

const statusLabels: Record<string, string> = {
    active: 'Activa',
    inactive: 'Inactiva',
    suspended: 'Suspendida',
    archived: 'Archivada',
};

const statusLabel = (status: string): string => statusLabels[status] ?? status;

/** Downloads the offices on screen as a CSV that Excel opens with accents intact. */
function exportCsv(offices: Office[]): void {
    const quote = (value: string | number | null): string =>
        `"${String(value ?? '').replaceAll('"', '""')}"`;
    const rows = [
        [
            'Organización',
            'NIT',
            'Código organización',
            'Oficina',
            'Código oficina',
            'Principal',
            'Ciudad',
            'Usuarios',
            'Empresas',
            'Estado',
            'Creada',
        ],
        ...offices.map((office) => [
            office.organization,
            office.organization_tax_id,
            office.organization_code,
            office.name,
            office.code,
            office.primary ? 'Sí' : 'No',
            office.city,
            office.users,
            office.companies,
            statusLabel(office.status),
            office.created_at.slice(0, 10),
        ]),
    ];
    const csv = '﻿' + rows.map((row) => row.map(quote).join(',')).join('\r\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(
        new Blob([csv], { type: 'text/csv;charset=utf-8' }),
    );
    link.download = `oficinas-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

export default function Offices({
    offices,
    filters,
}: {
    offices: Office[];
    filters: { search?: string };
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState('');
    const [city, setCity] = useState('');
    const [page, setPage] = useState(1);
    const cities = [
        ...new Set(
            offices
                .map((office) => office.city)
                .filter((value): value is string => Boolean(value)),
        ),
    ].sort((a, b) => a.localeCompare(b, 'es'));
    const statuses = [...new Set(offices.map((office) => office.status))];
    const visible = offices.filter(
        (office) =>
            (status === '' || office.status === status) &&
            (city === '' || office.city === city),
    );
    const pages = Math.max(1, Math.ceil(visible.length / PAGE_SIZE));
    const current = Math.min(page, pages);
    const shown = visible.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);
    const kpis = [
        {
            label: 'Oficinas registradas',
            value: offices.length,
            icon: Building2,
        },
        {
            label: 'Usuarios totales',
            value: offices.reduce((sum, office) => sum + office.users, 0),
            icon: Users,
        },
        {
            label: 'Empresas cliente',
            value: offices.reduce((sum, office) => sum + office.companies, 0),
            icon: Layers,
        },
        {
            label: 'Oficinas activas',
            value: offices.filter((office) => office.status === 'active')
                .length,
            icon: BadgeCheck,
        },
    ];
    const iconButton =
        'grid size-8 place-items-center rounded-lg border border-slate-300 text-slate-700 hover:border-[#7a0010]/50 hover:text-[#7a0010]';

    return (
        <>
            <Head title="Oficinas · Control Center" />
            <div className="space-y-4">
                <header className="border-l-4 border-[#e0a526] pl-5">
                    <h1 className="font-serif text-4xl font-bold text-slate-900">
                        Oficinas
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-slate-600">
                        Estructura de cada organización cliente para soporte,
                        métricas y consumo. La asignación de personal la hace el
                        administrador de cada oficina, no el Control Center.
                    </p>
                </header>
                <Notice />

                <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    {kpis.map((kpi) => (
                        <div
                            key={kpi.label}
                            className="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]"
                        >
                            <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-[#7a0010] text-white">
                                <kpi.icon className="size-6" />
                            </span>
                            <span>
                                <span className="block font-serif text-2xl leading-tight font-bold text-slate-900">
                                    {kpi.value}
                                </span>
                                <span className="block text-sm text-slate-700">
                                    {kpi.label}
                                </span>
                            </span>
                        </div>
                    ))}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_12px_30px_-22px_rgba(60,0,0,.45)]">
                    <div className="mb-3 flex flex-wrap items-center gap-3">
                        <form
                            className="relative w-full max-w-sm"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get(
                                    '/control-center/offices',
                                    { search },
                                    { preserveState: true },
                                );
                            }}
                        >
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
                            <input
                                className={`${ccInput} pl-9`}
                                placeholder="Buscar oficina u organización…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </form>
                        <select
                            className={`${ccInput} max-w-[200px]`}
                            value={status}
                            onChange={(e) => {
                                setStatus(e.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Todos los estados</option>
                            {statuses.map((key) => (
                                <option key={key} value={key}>
                                    {statusLabel(key)}
                                </option>
                            ))}
                        </select>
                        <select
                            className={`${ccInput} max-w-[220px]`}
                            value={city}
                            onChange={(e) => {
                                setCity(e.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Todas las ciudades</option>
                            {cities.map((name) => (
                                <option key={name} value={name}>
                                    {name}
                                </option>
                            ))}
                        </select>
                        <button
                            type="button"
                            onClick={() => exportCsv(visible)}
                            disabled={visible.length === 0}
                            className="ml-auto inline-flex h-10 items-center gap-2 rounded-lg border-2 border-[#7a0010]/70 px-4 text-sm font-bold text-[#7a0010] transition hover:bg-[#7a0010]/5 disabled:opacity-40"
                        >
                            <Download className="size-4" /> Exportar
                        </button>
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-slate-200">
                        <table className="min-w-full text-[13px] text-slate-800">
                            <thead>
                                <tr className="bg-[#7a0010] text-left text-[13px] font-semibold whitespace-nowrap text-white">
                                    <th className="px-4 py-3">Organización</th>
                                    <th className="px-3 py-3">Oficina</th>
                                    <th className="px-3 py-3">Ciudad</th>
                                    <th className="px-3 py-3">Usuarios</th>
                                    <th className="px-3 py-3">Empresas</th>
                                    <th className="px-3 py-3">Estado</th>
                                    <th className="px-3 py-3">Creada</th>
                                    <th className="px-3 py-3 text-center">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {shown.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-4 py-10 text-center text-slate-500"
                                        >
                                            {offices.length === 0
                                                ? 'No existen oficinas registradas.'
                                                : 'Ninguna oficina coincide con los filtros.'}
                                        </td>
                                    </tr>
                                )}
                                {shown.map((office) => {
                                    const organizationUrl = `/control-center/organizations/${office.organization_public_id}`;

                                    return (
                                        <tr
                                            key={office.id}
                                            className="hover:bg-slate-50"
                                        >
                                            <td className="min-w-[220px] px-4 py-3">
                                                <Link
                                                    href={`${organizationUrl}?tab=offices`}
                                                    className="flex items-center gap-3"
                                                >
                                                    <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-[#7a0010]/10 text-[#7a0010]">
                                                        <Building2 className="size-5" />
                                                    </span>
                                                    <span>
                                                        <span className="block leading-tight font-bold text-slate-900">
                                                            {
                                                                office.organization
                                                            }
                                                        </span>
                                                        <span className="block text-xs text-slate-500">
                                                            {office.organization_tax_id
                                                                ? `NIT ${office.organization_tax_id}`
                                                                : (office.organization_code ??
                                                                  '—')}
                                                        </span>
                                                    </span>
                                                </Link>
                                            </td>
                                            <td className="min-w-[180px] px-3 py-3">
                                                <span className="flex flex-wrap items-center gap-1.5 leading-tight font-semibold text-slate-900">
                                                    {office.name}
                                                    {office.primary && (
                                                        <span className="rounded-full bg-[#e0a526]/25 px-2 py-0.5 text-[10px] font-bold text-[#7a4b00]">
                                                            Principal
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="block font-mono text-[11px] text-slate-500 uppercase">
                                                    {office.code}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                <span className="flex items-center gap-1.5 text-slate-700">
                                                    <MapPin className="size-4 shrink-0 text-slate-500" />
                                                    {office.city ?? '—'}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                <span className="flex items-center gap-1.5 font-semibold">
                                                    <Users className="size-4 text-slate-500" />
                                                    {office.users}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                <span className="flex items-center gap-1.5 font-semibold">
                                                    <Layers className="size-4 text-slate-500" />
                                                    {office.companies}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                <Pill
                                                    tone={
                                                        statusTone[
                                                            office.status
                                                        ]
                                                    }
                                                >
                                                    {statusLabel(office.status)}
                                                </Pill>
                                            </td>
                                            <td className="px-3 py-3 whitespace-nowrap">
                                                <span className="flex items-center gap-1.5 text-slate-700">
                                                    <CalendarDays className="size-4 text-[#7a0010]" />
                                                    {date(office.created_at)}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">
                                                <div className="flex items-center justify-center gap-1.5">
                                                    <Link
                                                        href={`${organizationUrl}?tab=offices`}
                                                        className={iconButton}
                                                        title="Ver oficinas de la organización"
                                                        aria-label="Ver"
                                                    >
                                                        <Eye className="size-4" />
                                                    </Link>
                                                    <Link
                                                        href={`${organizationUrl}?tab=users`}
                                                        className={iconButton}
                                                        title="Usuarios de la organización"
                                                        aria-label="Usuarios"
                                                    >
                                                        <Users className="size-4" />
                                                    </Link>
                                                    <RowMenu
                                                        links={[
                                                            {
                                                                label: 'Resumen de la organización',
                                                                href: organizationUrl,
                                                            },
                                                            {
                                                                label: 'Consumo',
                                                                href: `${organizationUrl}?tab=usage`,
                                                            },
                                                            {
                                                                label: 'Soporte',
                                                                href: `${organizationUrl}?tab=support`,
                                                            },
                                                            {
                                                                label: 'Actividad',
                                                                href: `${organizationUrl}?tab=activity`,
                                                            },
                                                        ]}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 px-1">
                        <p className="text-sm text-slate-600">
                            {visible.length === 0
                                ? 'Sin oficinas para mostrar'
                                : `Mostrando ${(current - 1) * PAGE_SIZE + 1} a ${(current - 1) * PAGE_SIZE + shown.length} de ${visible.length} oficina${visible.length === 1 ? '' : 's'}`}
                        </p>
                        <div className="flex items-center gap-1.5">
                            <button
                                type="button"
                                aria-label="Página anterior"
                                disabled={current === 1}
                                onClick={() => setPage(current - 1)}
                                className={`${iconButton} size-9 disabled:opacity-40`}
                            >
                                <ChevronLeft className="size-4" />
                            </button>
                            {Array.from({ length: pages }, (_, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    onClick={() => setPage(index + 1)}
                                    aria-current={
                                        current === index + 1
                                            ? 'page'
                                            : undefined
                                    }
                                    className={`grid size-9 place-items-center rounded-lg text-sm font-bold ${current === index + 1 ? 'bg-[#7a0010] text-white' : 'border border-slate-300 text-slate-700 hover:border-[#7a0010]/50'}`}
                                >
                                    {index + 1}
                                </button>
                            ))}
                            <button
                                type="button"
                                aria-label="Página siguiente"
                                disabled={current === pages}
                                onClick={() => setPage(current + 1)}
                                className={`${iconButton} size-9 disabled:opacity-40`}
                            >
                                <ChevronRight className="size-4" />
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
